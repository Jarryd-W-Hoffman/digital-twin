<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Actions\RemoveAssetFromTwin;
use App\Models\ProjectAsset;

it('takes the layer out in a new version and keeps the upload', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create(['name' => 'Pump']);
    resolve(AddAssetToTwin::class)->handle($twin, $user, $asset);

    $version = resolve(RemoveAssetFromTwin::class)->handle($twin, $user, $asset);

    expect($version?->number)->toBe(3)
        ->and($version?->note)->toBe('Removed Pump')
        ->and($version?->definition['models'])->toBe([])
        ->and($asset->fresh())->not->toBeNull();
});

it('saves nothing when the twin does not show the upload', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create();

    expect(resolve(RemoveAssetFromTwin::class)->handle($twin, $user, $asset))->toBeNull()
        ->and($twin->versions()->count())->toBe(1);
});
