<?php

declare(strict_types=1);

use App\Actions\DeleteTwinAsset;
use App\Actions\StoreTwinAsset;
use App\Models\TwinAsset;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('removes the files and the layer in a new version', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = resolve(StoreTwinAsset::class)->handle($twin, $user, glbUpload(), 'Pump');

    resolve(DeleteTwinAsset::class)->handle($asset, $user);
    $latest = $twin->latestVersion()->sole();

    expect(TwinAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($latest->number)->toBe(3)
        ->and($latest->note)->toBe('Removed Pump')
        ->and($latest->definition['models'])->toBe([]);
});

it('saves no version when the latest one no longer shows the asset', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = TwinAsset::factory()->for($twin)->create();

    resolve(DeleteTwinAsset::class)->handle($asset, $user);

    expect($twin->versions()->count())->toBe(1);
});
