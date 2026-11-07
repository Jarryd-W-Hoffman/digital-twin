<?php

declare(strict_types=1);

use App\Actions\ConvertPointCloud;
use App\Actions\ConvertTerrain;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Runs the real PDAL, py3dtiles and meridian-terrain, so it only runs
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
        ->and($lon)->toEqualWithDelta(174.751, 0.01);
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
