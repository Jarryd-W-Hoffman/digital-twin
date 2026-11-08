<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Actions\RemoveAssetFromTwin;
use App\Actions\SaveTwinVersion;
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

it('unbinds assets from the features of the layer it takes out, and only those', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $shed = ProjectAsset::factory()->for($twin->project)->bim()->create();
    $pump = ProjectAsset::factory()->for($twin->project)->create(['name' => 'Pump']);
    resolve(AddAssetToTwin::class)->handle($twin, $user, $shed);
    $definition = resolve(AddAssetToTwin::class)->handle($twin, $user, $pump)?->definition;
    [$shedLayer, $pumpLayer] = array_column($definition['models'], 'id');
    $definition['assets'][0]['feature'] = ['layer' => $shedLayer, 'id' => '2ntlfaHQjB7hg8G8Z3wX3z'];
    $definition['assets'][1]['feature'] = ['layer' => $pumpLayer, 'id' => 'impeller'];
    resolve(SaveTwinVersion::class)->handle($twin, $user, $definition, 'Bound');

    $assets = resolve(RemoveAssetFromTwin::class)->handle($twin, $user, $shed)?->definition['assets'];

    expect($assets[0])->not->toHaveKey('feature')
        ->and($assets[1]['feature'])->toBe(['layer' => $pumpLayer, 'id' => 'impeller']);
});
