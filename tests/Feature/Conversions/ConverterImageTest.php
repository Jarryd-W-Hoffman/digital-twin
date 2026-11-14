<?php

declare(strict_types=1);

use App\Actions\ConvertIfc;
use App\Actions\ConvertImagery;
use App\Actions\ConvertPointCloud;
use App\Actions\ConvertTerrain;
use App\Actions\ConvertVector;
use App\Actions\MeasureSurveys;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Enums\SurveyComparisonStatus;
use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use App\Models\SurveyComparison;
use App\Models\Twin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Runs the real PDAL, py3dtiles, meridian-terrain, meridian-ifc, meridian-vector
 * meridian-imagery and meridian-volume, so it only runs
 * inside the converter image:
 *
 *     docker run --rm -v "$PWD":/var/www/html -e MERIDIAN_CONVERTER_TESTS=1 \
 *         meridian-converter php vendor/bin/pest tests/Feature/Conversions
 */

beforeEach(function (): void {
    Process::preventStrayProcesses(false);
    Storage::fake('local');
    $this->work = storage_path('framework/testing/converter-'.Str::random(8));
    File::ensureDirectoryExists($this->work);
});

afterEach(fn () => File::deleteDirectory($this->work));

/**
 * 20,000 points over 200 m around Auckland's waterfront in NZTM (EPSG:2193),
 * written by PDAL as LAS (carrying its CRS) and E57 (carrying none).
 */
function scanOfAuckland(string $work, string $writer): string
{
    $path = $work.'/scan.'.($writer === 'writers.las' ? 'las' : 'e57');
    $pipeline = [
        ['type' => 'readers.faux', 'mode' => 'uniform', 'count' => 20000, 'bounds' => '([1756000,1756200],[5920000,5920200],[0,30])'],
        ['type' => $writer, 'filename' => $path, ...($writer === 'writers.las' ? ['a_srs' => 'EPSG:2193'] : [])],
    ];
    File::put($work.'/make.json', (string) json_encode($pipeline));
    Process::run(['pdal', 'pipeline', $work.'/make.json'])->throw();

    return $path;
}

/**
 * The latitude and longitude of the tileset root's ECEF translation.
 *
 * @return array{0: float, 1: float}
 */
function rootLatLon(string $tilesetJson): array
{
    /** @var array{root: array{transform: list<float>}} $tileset */
    $tileset = json_decode($tilesetJson, true);
    [$x, $y, $z] = array_slice($tileset['root']['transform'], 12, 3);
    $a = 6378137.0;
    $e2 = 6.69437999014e-3;
    $p = sqrt($x * $x + $y * $y);
    $lat = atan2($z, $p * (1 - $e2));

    foreach (range(1, 5) as $_) {
        $n = $a / sqrt(1 - $e2 * sin($lat) ** 2);
        $lat = atan2($z + $e2 * $n * sin($lat), $p);
    }

    return [rad2deg($lat), rad2deg(atan2($y, $x))];
}

it('tiles a LAS scan where it is on the globe', function (): void {
    $asset = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::PointCloud, 'original_name' => 'quay.las', 'options' => []]);
    Storage::disk('local')->put($asset->sourceKey(), File::get(scanOfAuckland($this->work, 'writers.las')));

    resolve(ConvertPointCloud::class)->handle($asset);

    [$lat, $lon] = rootLatLon((string) Storage::disk('local')->get($asset->directory.'/tileset.json'));
    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($asset->files)->toBeGreaterThan(1)
        ->and($lat)->toEqualWithDelta(-36.852, 0.01)
        ->and($lon)->toEqualWithDelta(174.751, 0.01)
        ->and($asset->details)->toBe(['surface' => 'surface.tif'])
        ->and(mb_substr((string) Storage::disk('local')->get($asset->directory.'/surface.tif'), 0, 4, '8bit'))->toBeIn(["II*\0", "II+\0"]);
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs PDAL and py3dtiles (the converter image).');

it('tiles an E57 scan, georeferenced by the EPSG code given and thinned', function (): void {
    $asset = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::PointCloud, 'original_name' => 'quay.e57', 'options' => ['epsg' => 2193, 'spacing' => 2.0]]);
    Storage::disk('local')->put($asset->sourceKey(), File::get(scanOfAuckland($this->work, 'writers.e57')));

    resolve(ConvertPointCloud::class)->handle($asset);

    [$lat, $lon] = rootLatLon((string) Storage::disk('local')->get($asset->directory.'/tileset.json'));
    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($lat)->toEqualWithDelta(-36.852, 0.01)
        ->and($lon)->toEqualWithDelta(174.751, 0.01);
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs PDAL and py3dtiles (the converter image).');

it('tiles an E57 scan without a CRS in local metres', function (): void {
    $asset = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::PointCloud, 'original_name' => 'room.e57', 'options' => []]);
    Storage::disk('local')->put($asset->sourceKey(), File::get(scanOfAuckland($this->work, 'writers.e57')));

    resolve(ConvertPointCloud::class)->handle($asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->crs)->toBeNull()
        ->and(Storage::disk('local')->exists($asset->directory.'/tileset.json'))->toBeTrue();
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs PDAL and py3dtiles (the converter image).');

/**
 * A 2 km DEM at 2 m in NZTM near Auckland: a 100 m hill 400 m north of its
 * centre on 20 m ground, with a corner of no-data, written by GDAL as a GeoTIFF (with or without
 * its coordinate system).
 */
function demOfAuckland(string $work, bool $georeferenced = true): string
{
    $path = $work.'/dem.tif';
    $script = <<<'PY'
        import sys
        import numpy as np
        from osgeo import gdal, osr
        gdal.UseExceptions()
        yy, xx = np.mgrid[0:1000, 0:1000]
        z = 20 + 80 * np.exp(-(((xx - 500) ** 2 + (yy - 300) ** 2) / (2 * 180 ** 2)))
        z[:100, :100] = -9999
        d = gdal.GetDriverByName("GTiff").Create(sys.argv[1], 1000, 1000, 1, gdal.GDT_Float32)
        d.SetGeoTransform((1756000, 2, 0, 5921000, 0, -2))
        if sys.argv[2] == "1":
            s = osr.SpatialReference()
            s.ImportFromEPSG(2193)
            d.SetProjection(s.ExportToWkt())
        b = d.GetRasterBand(1)
        b.SetNoDataValue(-9999)
        b.WriteArray(z.astype(np.float32))
        d = None
        PY;
    File::put($work.'/dem.py', $script);
    Process::run(['python3', $work.'/dem.py', $path, $georeferenced ? '1' : '0'])->throw();

    return $path;
}

/**
 * The lowest and highest heights in a quantized-mesh tile's header.
 *
 * @return array{0: float, 1: float}
 */
function tileHeights(string $tile): array
{
    /** @var array{1: float, 2: float} $heights */
    $heights = unpack('g2', mb_substr($tile, 24, 8, '8bit'));

    return [$heights[1], $heights[2]];
}

/**
 * A quantized-mesh tile's vertices as longitude, latitude and height.
 *
 * @return list<array{0: float, 1: float, 2: float}>
 */
function tileVertices(string $tile, int $z, int $x, int $y): array
{
    [$low, $high] = tileHeights($tile);
    /** @var array{1: int} $count */
    $count = unpack('V', mb_substr($tile, 88, 4, '8bit'));
    /** @var list<int> $values */
    $values = array_values((array) unpack('v*', mb_substr($tile, 92, 6 * $count[1], '8bit')));
    $axes = [];

    foreach (array_chunk($values, $count[1]) as $axis) {
        $sum = 0;
        $axes[] = array_map(function (int $value) use (&$sum): int {
            $sum += ($value >> 1) ^ -($value & 1);

            return $sum;
        }, $axis);
    }

    $size = 180 / 2 ** $z;

    return array_map(fn (int $u, int $v, int $h): array => [
        -180 + ($x + $u / 32767) * $size,
        -90 + ($y + $v / 32767) * $size,
        $low + $h / 32767 * ($high - $low),
    ], ...$axes);
}

it('tiles a GeoTIFF DEM as quantized-mesh terrain', function (): void {
    $asset = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::Terrain, 'original_name' => 'ground.tif', 'options' => []]);
    Storage::disk('local')->put($asset->sourceKey(), File::get(demOfAuckland($this->work)));

    resolve(ConvertTerrain::class)->handle($asset);

    /** @var array{format: string, maxzoom: int, available: list<list<array{startX: int, startY: int, endX: int, endY: int}>>, extensions: list<string>} $layer */
    $layer = json_decode((string) Storage::disk('local')->get($asset->directory.'/layer.json'), true);
    $deepest = $layer['available'][$layer['maxzoom']][0];
    $summit = collect(Storage::disk('local')->allFiles($asset->directory.'/'.$layer['maxzoom']))
        ->map(fn (string $tile): array => tileHeights((string) Storage::disk('local')->get($tile)))
        ->max(fn (array $heights): float => $heights[1]);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($asset->log)->toContain('to 100 m')
        ->and($asset->details)->toBe(['surface' => 'surface.tif'])
        ->and($layer['format'])->toBe('quantized-mesh-1.0')
        ->and($layer['extensions'])->toBe(['octvertexnormals'])
        ->and($layer['maxzoom'])->toBe(16)
        ->and($layer['available'][0])->toBe([['startX' => 0, 'startY' => 0, 'endX' => 1, 'endY' => 0]])
        ->and(Storage::disk('local')->exists($asset->directory.'/0/0/0.terrain'))->toBeTrue()
        ->and(Storage::disk('local')->exists(sprintf('%s/16/%d/%d.terrain', $asset->directory, $deepest['startX'], $deepest['startY'])))->toBeTrue()
        ->and($summit)->toEqualWithDelta(100, 0.5);

    // The summit is north of the DEM's centre: read the other way up, it
    // would be as far south.
    $peak = collect(Storage::disk('local')->allFiles($asset->directory.'/16'))
        ->flatMap(function (string $tile): array {
            [$x, $y] = array_map(intval(...), explode('/', (string) preg_replace('#^.*/16/(\d+)/(\d+)\.terrain$#', '$1/$2', $tile)));

            return tileVertices((string) Storage::disk('local')->get($tile), 16, $x, $y);
        })
        ->sortByDesc(fn (array $vertex): float => $vertex[2])
        ->first();

    expect($peak[2])->toEqualWithDelta(100, 0.5)
        ->and($peak[1])->toEqualWithDelta(-36.8493, 0.0005)
        ->and($peak[0])->toEqualWithDelta(174.7611, 0.0005);
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs meridian-terrain (the converter image).');

it('asks for an EPSG code when a DEM has no coordinate system, then uses it', function (): void {
    $asset = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::Terrain, 'original_name' => 'bare.tif', 'options' => []]);
    Storage::disk('local')->put($asset->sourceKey(), File::get(demOfAuckland($this->work, georeferenced: false)));

    expect(fn () => resolve(ConvertTerrain::class)->handle($asset))
        ->toThrow(ConversionFailed::class, 'The DEM has no coordinate system. Retry with its EPSG code.');

    $asset->update(['options' => ['epsg' => 2193]]);
    resolve(ConvertTerrain::class)->handle($asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->crs)->toBe('EPSG:2193');
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs meridian-terrain (the converter image).');

/**
 * A 20 m by 12 m shed, written by scripts/generate-ifc.py with these options.
 *
 * @param  list<string>  $options
 */
function shedIfc(string $work, array $options = []): string
{
    $path = $work.'/shed.ifc';
    Process::run(['python', base_path('scripts/generate-ifc.py'), $path, ...$options])->throw();

    return $path;
}

/**
 * A GLB's JSON chunk, and the bounds of all its positions.
 *
 * @return array{0: array{nodes: list<array{name: string, extras: array<string, string>}>, extensionsUsed: list<string>, extensions: array{EXT_structural_metadata: array{propertyTables: list<array{count: int}>}}}, 1: array{0: list<float>, 1: list<float>}}
 */
function glbContents(string $glb): array
{
    $length = unpack('V', mb_substr($glb, 12, 4, '8bit'))[1];
    /** @var array{nodes: list<array{name: string, extras: array<string, string>}>, extensionsUsed: list<string>, extensions: array{EXT_structural_metadata: array{propertyTables: list<array{count: int}>}}, meshes: list<array{primitives: list<array{attributes: array{POSITION: int}}>}>, accessors: list<array{min?: list<float>, max?: list<float>}>} $document */
    $document = json_decode(mb_substr($glb, 20, $length, '8bit'), true);
    $positions = collect($document['meshes'])->flatMap(fn (array $mesh): array => array_map(
        fn (array $primitive): array => $document['accessors'][$primitive['attributes']['POSITION']],
        $mesh['primitives'],
    ));

    return [$document, [
        array_map(fn (int $axis): float => $positions->min(fn (array $a): float => $a['min'][$axis]), [0, 1, 2]),
        array_map(fn (int $axis): float => $positions->max(fn (array $a): float => $a['max'][$axis]), [0, 1, 2]),
    ]];
}

it('converts an IFC model with a map conversion to a GLB of its elements, where it is', function (): void {
    $asset = ProjectAsset::factory()->bim()->processing()->create(['details' => null]);
    Storage::disk('local')->put($asset->sourceKey(), File::get(shedIfc($this->work, ['--map', 'EPSG:2193', '1756100', '5920100', '30'])));

    resolve(ConvertIfc::class)->handle($asset);

    [$document, [$low, $high]] = glbContents((string) Storage::disk('local')->get($asset->directory.'/model.glb'));
    /** @var list<array{id: string, name: string, ifcClass: string, storey: string}> $elements */
    $elements = json_decode((string) Storage::disk('local')->get($asset->directory.'/elements.json'), true);
    $georeference = $asset->refresh()->details['georeference'] ?? [];

    expect($asset->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($asset->details['elements'] ?? null)->toBe(6)
        ->and(array_column($elements, 'ifcClass'))->toBe(['IfcSlab', 'IfcWall', 'IfcWall', 'IfcWall', 'IfcWall', 'IfcRoof'])
        ->and($elements[1])->toMatchArray(['name' => 'South wall', 'storey' => 'Ground floor'])
        ->and(array_column($document['nodes'], 'name'))->toBe(array_column($elements, 'id'))
        ->and($document['nodes'][5]['extras']['name'])->toBe('Roof')
        // Each element is a feature a viewer can pick and colour.
        ->and($document['extensionsUsed'])->toBe(['EXT_mesh_features', 'EXT_structural_metadata'])
        ->and($document['extensions']['EXT_structural_metadata']['propertyTables'][0]['count'])->toBe(6)
        // Millimetres in the file, metres out, as glTF that Cesium turns to east (z), north (x) and up (y).
        ->and($low[0])->toEqualWithDelta(0, 0.01)
        ->and($high[0])->toEqualWithDelta(12, 0.01)
        ->and($low[1])->toEqualWithDelta(-0.2, 0.01)
        ->and($high[1])->toEqualWithDelta(5.15, 0.01)
        ->and($low[2])->toEqualWithDelta(0, 0.01)
        ->and($high[2])->toEqualWithDelta(20, 0.01)
        ->and($georeference['source'] ?? null)->toBe('IfcMapConversion')
        ->and($georeference['lat'] ?? null)->toEqualWithDelta(-36.852, 0.01)
        ->and($georeference['lon'] ?? null)->toEqualWithDelta(174.751, 0.01)
        ->and($georeference['headingDeg'] ?? null)->toEqualWithDelta(-30, 0.001);
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs meridian-ifc (the converter image).');

it('recentres an IFC model drawn far from its origin, and places it by its site', function (): void {
    $asset = ProjectAsset::factory()->bim()->processing()->create(['details' => null]);
    Storage::disk('local')->put($asset->sourceKey(), File::get(shedIfc($this->work, ['--site', '-36.85', '174.76', '--true-north', '15', '--offset', '5000', '0'])));

    resolve(ConvertIfc::class)->handle($asset);

    [, [$low, $high]] = glbContents((string) Storage::disk('local')->get($asset->directory.'/model.glb'));
    $georeference = $asset->refresh()->details['georeference'] ?? [];
    // The shed's centre is 5,010 m along the project's x axis, which is 15° south of east.
    $east = 5010 * cos(deg2rad(15)) + 6 * sin(deg2rad(15));
    $north = -5010 * sin(deg2rad(15)) + 6 * cos(deg2rad(15));

    expect($asset->crs)->toBeNull()
        ->and($low[2])->toEqualWithDelta(-10, 0.01)
        ->and($high[2])->toEqualWithDelta(10, 0.01)
        ->and($georeference['source'] ?? null)->toBe('IfcSite')
        ->and($georeference['headingDeg'] ?? null)->toEqualWithDelta(15, 0.001)
        ->and($georeference['lat'] ?? null)->toEqualWithDelta(-36.85 + rad2deg($north / 6_378_137), 0.00001)
        ->and($georeference['lon'] ?? null)->toEqualWithDelta(174.76 + rad2deg($east / (6_378_137 * cos(deg2rad(-36.85)))), 0.00001);
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs meridian-ifc (the converter image).');

it('converts an IFC model that says nothing of where it is', function (): void {
    $asset = ProjectAsset::factory()->bim()->processing()->create(['details' => null]);
    Storage::disk('local')->put($asset->sourceKey(), File::get(shedIfc($this->work)));

    resolve(ConvertIfc::class)->handle($asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->details)->toBe(['elements' => 6, 'georeference' => null])
        ->and($asset->log)->toContain('Not georeferenced');
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs meridian-ifc (the converter image).');

/**
 * A 50 m lot in NZTM near Auckland, written by GDAL as a zipped shapefile and
 * as a DXF drawing (which carries no coordinate system), and a borehole as
 * GeoJSON in longitude and latitude with a height.
 *
 * @return array{shapefile: string, dxf: string, geojson: string}
 */
function siteLayers(string $work): array
{
    $script = <<<'PY'
        import json, sys, zipfile
        from osgeo import gdal, ogr, osr
        gdal.UseExceptions()
        work = sys.argv[1]
        nztm = osr.SpatialReference()
        nztm.ImportFromEPSG(2193)
        shp = ogr.GetDriverByName("ESRI Shapefile").CreateDataSource(work + "/lots.shp")
        layer = shp.CreateLayer("lots", nztm, ogr.wkbPolygon)
        layer.CreateField(ogr.FieldDefn("name", ogr.OFTString))
        feature = ogr.Feature(layer.GetLayerDefn())
        feature.SetField("name", "Lot 1")
        feature.SetGeometry(ogr.CreateGeometryFromWkt("POLYGON ((1756000 5920000, 1756050 5920000, 1756050 5920050, 1756000 5920050, 1756000 5920000))"))
        layer.CreateFeature(feature)
        shp = None
        with zipfile.ZipFile(work + "/lots.zip", "w") as z:
            for ext in ("shp", "shx", "dbf", "prj"):
                z.write(work + "/lots." + ext, "lots." + ext)
        dxf = ogr.GetDriverByName("DXF").CreateDataSource(work + "/plan.dxf")
        entities = dxf.CreateLayer("entities")
        line = ogr.Feature(entities.GetLayerDefn())
        line.SetGeometry(ogr.CreateGeometryFromWkt("LINESTRING (1756000 5920000, 1756100 5920000)"))
        entities.CreateFeature(line)
        dxf = None
        with open(work + "/holes.geojson", "w") as f:
            json.dump({"type": "FeatureCollection", "features": [{"type": "Feature", "properties": {"id": "BH-1", "depth": 12.5}, "geometry": {"type": "Point", "coordinates": [174.76, -36.85, 12]}}]}, f)
        PY;
    File::put($work.'/layers.py', $script);
    Process::run(['python3', $work.'/layers.py', $work])->throw();

    return ['shapefile' => $work.'/lots.zip', 'dxf' => $work.'/plan.dxf', 'geojson' => $work.'/holes.geojson'];
}

it('converts a zipped shapefile and GeoJSON to GeoJSON in longitude and latitude', function (): void {
    $layers = siteLayers($this->work);
    $lots = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::Vector, 'original_name' => 'lots.zip', 'options' => []]);
    Storage::disk('local')->put($lots->sourceKey(), File::get($layers['shapefile']));
    $holes = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::Vector, 'original_name' => 'holes.geojson', 'options' => []]);
    Storage::disk('local')->put($holes->sourceKey(), File::get($layers['geojson']));

    resolve(ConvertVector::class)->handle($lots);
    resolve(ConvertVector::class)->handle($holes);

    /** @var array{features: list<array{geometry: array{type: string, coordinates: list<list<array{0: float, 1: float}>>|list<float>}, properties: array<string, mixed>}>} $lot */
    $lot = json_decode((string) Storage::disk('local')->get($lots->directory.'/layer.geojson'), true);
    /** @var array{features: list<array{geometry: array{coordinates: list<float>}, properties: array<string, mixed>}>} $hole */
    $hole = json_decode((string) Storage::disk('local')->get($holes->directory.'/layer.geojson'), true);

    expect($lots->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($lots->crs)->toBe('EPSG:2193')
        ->and($lots->details['features'] ?? null)->toBe(1)
        ->and($lot['features'][0]['properties'])->toBe(['name' => 'Lot 1', 'layer' => 'lots'])
        ->and($lot['features'][0]['geometry']['type'])->toBe('Polygon')
        ->and($lot['features'][0]['geometry']['coordinates'][0][0][0])->toEqualWithDelta(174.7498, 0.0005)
        ->and($lot['features'][0]['geometry']['coordinates'][0][0][1])->toEqualWithDelta(-36.8530, 0.0005)
        ->and($holes->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($hole['features'][0]['geometry']['coordinates'])->toBe([174.76, -36.85])
        ->and($hole['features'][0]['properties'])->toBe(['id' => 'BH-1', 'depth' => 12.5, 'layer' => 'holes']);
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs meridian-vector (the converter image).');

it('asks for an EPSG code for a DXF drawing, then uses it', function (): void {
    $asset = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::Vector, 'original_name' => 'plan.dxf', 'options' => []]);
    Storage::disk('local')->put($asset->sourceKey(), File::get(siteLayers($this->work)['dxf']));

    expect(fn () => resolve(ConvertVector::class)->handle($asset))
        ->toThrow(ConversionFailed::class, "Layer entities has no coordinate system. Give the file's EPSG code.");

    $asset->update(['options' => ['epsg' => 2193]]);
    resolve(ConvertVector::class)->handle($asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->log)->toContain('1 features in 1 layers (1 Line String)');
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs meridian-vector (the converter image).');

it('tiles a GeoTIFF orthophoto as web map tiles, from the bands it has', function (int $bands, int $type): void {
    $script = <<<'PY'
        import sys
        import numpy as np
        from osgeo import gdal, osr
        gdal.UseExceptions()
        bands, kind = int(sys.argv[2]), int(sys.argv[3])
        d = gdal.GetDriverByName("GTiff").Create(sys.argv[1], 400, 300, bands, kind)
        d.SetGeoTransform((1756000, 0.5, 0, 5920150, 0, -0.5))
        s = osr.SpatialReference()
        s.ImportFromEPSG(2193)
        d.SetProjection(s.ExportToWkt())
        for b in range(bands):
            d.GetRasterBand(b + 1).WriteArray((np.indices((300, 400))[b % 2] * (b + 1) * 40).astype(np.uint16))
        d = None
        PY;
    File::put($this->work.'/ortho.py', $script);
    Process::run(['python3', $this->work.'/ortho.py', $this->work.'/ortho.tif', (string) $bands, (string) $type])->throw();
    $asset = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::Imagery, 'original_name' => 'ortho.tif', 'options' => []]);
    Storage::disk('local')->put($asset->sourceKey(), File::get($this->work.'/ortho.tif'));

    resolve(ConvertImagery::class)->handle($asset);

    /** @var array{format: string, minZoom: int, maxZoom: int, bounds: list<float>} $info */
    $info = json_decode((string) Storage::disk('local')->get($asset->directory.'/imagery.json'), true);
    $deepest = Storage::disk('local')->allFiles($asset->directory.'/'.$info['maxZoom']);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($info['format'])->toBe('png')
        ->and($info['maxZoom'])->toBe(18)
        ->and($info['minZoom'])->toBe(10)
        ->and($info['bounds'][0])->toEqualWithDelta(174.7498, 0.0005)
        ->and($info['bounds'][3])->toEqualWithDelta(-36.8517, 0.0005)
        ->and($deepest)->not->toBeEmpty()
        ->and(mb_substr((string) Storage::disk('local')->get($deepest[0]), 1, 3, '8bit'))->toBe('PNG');
})->with([
    'RGB bytes' => [3, 1],
    'one 16-bit band' => [1, 2],
])->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs meridian-imagery (the converter image).');

it('measures the fill between two DEMs inside an area, and draws where', function (): void {
    $before = demOfAuckland($this->work);
    // The same ground with a 100 m square, 2 m higher: 20,000 m³ of fill.
    $script = <<<'PY'
        import sys
        from osgeo import gdal
        gdal.UseExceptions()
        d = gdal.Translate(sys.argv[2], sys.argv[1])
        b = d.GetRasterBand(1)
        z = b.ReadAsArray()
        z[600:650, 400:450] += 2
        b.WriteArray(z)
        d = None
        PY;
    File::put($this->work.'/raise.py', $script);
    Process::run(['python3', $this->work.'/raise.py', $before, $this->work.'/after.tif'])->throw();
    $twin = Twin::factory()->create();
    [$march, $april] = array_map(function (string $path) use ($twin): ProjectAsset {
        $survey = ProjectAsset::factory()->for($twin->project)->survey()->create();
        Storage::disk('local')->put($survey->directory.'/surface.tif', File::get($path));

        return $survey;
    }, [$before, $this->work.'/after.tif']);
    // A 300 m square in NZTM around the raised one, in longitude and latitude.
    $corners = Process::run(['python3', '-c', 'import json; from pyproj import Transformer; t = Transformer.from_crs(2193, 4326, always_xy=True); print(json.dumps([t.transform(e, n) for e, n in [(1756700, 5919900), (1757000, 5919900), (1757000, 5919600), (1756700, 5919600)]]))'])->throw()->output();
    /** @var list<array{0: float, 1: float}> $area */
    $area = json_decode($corners, true);
    $comparison = SurveyComparison::factory()->for($twin)->create(['area' => $area, 'surveys' => [$march->id, $april->id]]);

    resolve(MeasureSurveys::class)->handle($comparison);

    $result = $comparison->refresh()->results[0] ?? [];
    expect($comparison->status)->toBe(SurveyComparisonStatus::Ready)
        ->and($result['fill'] ?? null)->toEqualWithDelta(20_000, 200)
        ->and($result['cut'] ?? null)->toEqualWithDelta(0, 1)
        ->and($result['areaM2'] ?? null)->toEqualWithDelta(90_000, 500)
        ->and($result['highestFill'] ?? null)->toEqualWithDelta(2, 0.01)
        ->and(mb_substr((string) Storage::disk('local')->get($comparison->imageKey(1)), 1, 3, '8bit'))->toBe('PNG');
})->group('converter')->skip(! getenv('MERIDIAN_CONVERTER_TESTS'), 'Needs meridian-volume (the converter image).');
