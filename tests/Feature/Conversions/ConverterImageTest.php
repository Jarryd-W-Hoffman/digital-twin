<?php

declare(strict_types=1);

use App\Actions\ConvertPointCloud;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Runs the real PDAL and py3dtiles, so it only runs inside the converter
 * image:
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
