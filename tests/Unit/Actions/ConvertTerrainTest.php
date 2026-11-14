<?php

declare(strict_types=1);

use App\Actions\ConvertTerrain;
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
        'kind' => ProjectAssetKind::Terrain,
        'original_name' => 'Quay DEM.TIF',
        'options' => [],
    ]);
    Storage::disk('local')->put($this->asset->sourceKey(), "II*\0 elevation");
});

it('tiles a DEM into the library and drops the original', function (): void {
    fakeTerrainTiler();

    $asset = resolve(ConvertTerrain::class)->handle($this->asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->entry)->toBe('layer.json')
        ->and($asset->files)->toBe(3)
        ->and($asset->bytes)->toBe(36 + 12)
        ->and($asset->details)->toBe(['surface' => 'surface.tif'])
        ->and(Storage::disk('local')->get($asset->directory.'/surface.tif'))->toBe("II*\0 heights")
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($asset->progress)->toBe(100)
        ->and($asset->log)->toBe("Downloaded Quay DEM.TIF\nEPSG:2193, 120 tiles to zoom 16, heights 20.04 to 100 m\n3 files written")
        ->and(Storage::disk('local')->exists($asset->directory.'/layer.json'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->directory.'/0/1/0.terrain'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->sourceKey()))->toBeFalse()
        ->and(is_dir(storage_path('app/conversions/'.$asset->id)))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'meridian-terrain'
        && $process->command[1] === storage_path('app/conversions/'.$asset->id.'/source.tif')
        && $process->command[3] === '--jobs'
        && array_slice((array) $process->command, 5, 2) === ['--surface', storage_path('app/conversions/'.$asset->id.'/tiles/surface.tif')]
        && ! in_array('--srs', (array) $process->command, true));
});

it('tells the tiler the coordinate system it was given', function (): void {
    fakeTerrainTiler();
    $this->asset->update(['options' => ['epsg' => 27700]]);

    resolve(ConvertTerrain::class)->handle($this->asset);

    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, -2) === ['--srs', 'EPSG:27700']);
});

it('accepts BigTIFF and ASCII grids', function (string $name, string $header): void {
    fakeTerrainTiler();
    $this->asset->update(['original_name' => $name]);
    Storage::disk('local')->put($this->asset->sourceKey(), $header);

    expect(resolve(ConvertTerrain::class)->handle($this->asset)->status)->toBe(ProjectAssetStatus::Ready);
})->with([
    ['big.tif', "MM\0+ elevation"],
    ['motorola.tiff', "MM\0* elevation"],
    ['grid.asc', "  NCOLS 100\nnrows 100"],
]);

it('refuses files that are not elevation models', function (): void {
    fakeTerrainTiler();
    Storage::disk('local')->put($this->asset->sourceKey(), 'GIF89a');

    expect(fn () => resolve(ConvertTerrain::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'That is not a GeoTIFF or ASCII grid elevation model.');
});

it('passes on why the tiler failed, keeping the original for a retry', function (): void {
    fakeTerrainTiler(fail: 'The DEM has no coordinate system. Retry with its EPSG code.');

    expect(fn () => resolve(ConvertTerrain::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, "The DEM could not be tiled:\nThe DEM has no coordinate system. Retry with its EPSG code.");

    expect(Storage::disk('local')->exists($this->asset->sourceKey()))->toBeTrue()
        ->and(Storage::disk('local')->allFiles($this->asset->directory))->toBe([]);
});

it('refuses a run that wrote no layer.json', function (): void {
    fakeTerrainTiler(writes: false);

    expect(fn () => resolve(ConvertTerrain::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'meridian-terrain wrote no layer.json.');
});

it('copes with a tiler that prints no summary', function (): void {
    fakeTerrainTiler(summary: 'done');

    $asset = resolve(ConvertTerrain::class)->handle($this->asset);

    expect($asset->crs)->toBe('Unknown')
        ->and($asset->log)->toContain('Unknown, 0 tiles to zoom 0, heights 0 to 0 m');
});
