<?php

declare(strict_types=1);

use App\Actions\ConvertVector;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->asset = ProjectAsset::factory()->processing()->create([
        'kind' => ProjectAssetKind::Vector,
        'original_name' => 'Site plan.DXF',
        'options' => [],
    ]);
    Storage::disk('local')->put($this->asset->sourceKey(), '0 SECTION');
});

it('converts a map layer to GeoJSON in the library and drops the original', function (): void {
    fakeVectorConverter();

    $asset = resolve(ConvertVector::class)->handle($this->asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->entry)->toBe('layer.geojson')
        ->and($asset->files)->toBe(1)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($asset->details)->toBe(['features' => 1250, 'bounds' => [174.74, -36.86, 174.77, -36.84]])
        ->and($asset->log)->toBe("Downloaded Site plan.DXF\nEPSG:2193, 1,250 features in 2 layers (1200 Polygon, 50 LineString)\nGeoJSON written")
        ->and(Storage::disk('local')->exists($asset->directory.'/layer.geojson'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->sourceKey()))->toBeFalse()
        ->and(is_dir(storage_path('app/conversions/'.$asset->id)))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'meridian-vector'
        && $process->command[1] === storage_path('app/conversions/'.$asset->id.'/source.dxf')
        && array_slice((array) $process->command, 3) === ['--max-features', '250000', '--name', 'Site plan']);
});

it('tells the converter the coordinate system it was given', function (): void {
    fakeVectorConverter();
    $this->asset->update(['options' => ['epsg' => 2193]]);

    resolve(ConvertVector::class)->handle($this->asset);

    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, -2) === ['--srs', 'EPSG:2193']);
});

it('passes on why the converter failed, keeping the original for a retry', function (): void {
    fakeVectorConverter(fail: "Layer entities has no coordinate system. Give the file's EPSG code.");

    expect(fn () => resolve(ConvertVector::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, "The map layer could not be read:\nLayer entities has no coordinate system. Give the file's EPSG code.");

    expect(Storage::disk('local')->exists($this->asset->sourceKey()))->toBeTrue()
        ->and(Storage::disk('local')->allFiles($this->asset->directory))->toBe([]);
});

it('refuses a run that wrote no GeoJSON', function (): void {
    fakeVectorConverter(writes: false);

    expect(fn () => resolve(ConvertVector::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'meridian-vector wrote no layer.geojson.');
});

it('copes with a converter that prints no summary', function (): void {
    fakeVectorConverter(summary: 'done');

    $asset = resolve(ConvertVector::class)->handle($this->asset);

    expect($asset->crs)->toBe('Unknown')
        ->and($asset->details)->toBe(['features' => 0, 'bounds' => []])
        ->and($asset->log)->toContain('Unknown, 0 features in 0 layers ()');
});
