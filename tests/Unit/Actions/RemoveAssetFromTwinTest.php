<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Actions\RemoveAssetFromTwin;
use App\Enums\ProjectAssetKind;
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

it('takes away the terrain it came from, leaving other ground alone', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $terrain = ProjectAsset::factory()->for($twin->project)->create(['kind' => ProjectAssetKind::Terrain, 'entry' => 'layer.json', 'name' => 'DEM']);
    $other = ProjectAsset::factory()->for($twin->project)->create(['kind' => ProjectAssetKind::Terrain, 'entry' => 'layer.json']);
    resolve(AddAssetToTwin::class)->handle($twin, $user, $terrain);

    expect(resolve(RemoveAssetFromTwin::class)->handle($twin, $user, $other))->toBeNull();

    $version = resolve(RemoveAssetFromTwin::class)->handle($twin, $user, $terrain);

    expect($version?->note)->toBe('Removed DEM')
        ->and($version?->definition)->not->toHaveKey('terrain');
});
