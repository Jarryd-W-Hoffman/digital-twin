<?php

declare(strict_types=1);

use App\Actions\ConvertImagery;
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
        'kind' => ProjectAssetKind::Imagery,
        'original_name' => 'Quay ortho.tif',
        'options' => [],
    ]);
    Storage::disk('local')->put($this->asset->sourceKey(), "II*\0 pixels");
});

it('tiles an image into the library and drops the original', function (): void {
    fakeImageryTiler();

    $asset = resolve(ConvertImagery::class)->handle($this->asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->entry)->toBe('imagery.json')
        ->and($asset->files)->toBe(2)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($asset->details)->toBe(['bounds' => [174.74, -36.86, 174.77, -36.84], 'zooms' => [12, 20]])
        ->and($asset->log)->toBe("Downloaded Quay ortho.tif\nEPSG:2193, 12,000 × 8,000 pixels, 340 tiles at zooms 12 to 20\n2 files written")
        ->and(Storage::disk('local')->exists($asset->directory.'/imagery.json'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->directory.'/20/1/2.png'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->sourceKey()))->toBeFalse()
        ->and(is_dir(storage_path('app/conversions/'.$asset->id)))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'meridian-imagery'
        && $process->command[1] === storage_path('app/conversions/'.$asset->id.'/source.tif')
        && array_slice((array) $process->command, 3) === ['--jobs', '2']);
});

it('tells the tiler the coordinate system it was given', function (): void {
    fakeImageryTiler();
    $this->asset->update(['options' => ['epsg' => 27700]]);

    resolve(ConvertImagery::class)->handle($this->asset);

    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, -2) === ['--srs', 'EPSG:27700']);
});

it('refuses files that are not TIFFs', function (): void {
    fakeImageryTiler();
    Storage::disk('local')->put($this->asset->sourceKey(), 'GIF89a');

    expect(fn () => resolve(ConvertImagery::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'That is not a GeoTIFF image.');
});

it('passes on why the tiler failed, keeping the original for a retry', function (): void {
    fakeImageryTiler(fail: 'The image has no coordinate system. Give its EPSG code.');

    expect(fn () => resolve(ConvertImagery::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, "The image could not be tiled:\nThe image has no coordinate system. Give its EPSG code.");

    expect(Storage::disk('local')->exists($this->asset->sourceKey()))->toBeTrue()
        ->and(Storage::disk('local')->allFiles($this->asset->directory))->toBe([]);
});

it('refuses a run that wrote no imagery.json', function (): void {
    fakeImageryTiler(writes: false);

    expect(fn () => resolve(ConvertImagery::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'meridian-imagery wrote no imagery.json.');
});

it('copes with a tiler that prints no summary', function (): void {
    fakeImageryTiler(summary: 'done');

    $asset = resolve(ConvertImagery::class)->handle($this->asset);

    expect($asset->crs)->toBe('Unknown')
        ->and($asset->details)->toBe(['bounds' => [], 'zooms' => [0, 0]]);
});
