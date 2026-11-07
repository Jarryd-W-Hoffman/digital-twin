<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Enums\ProjectAssetKind;
use App\Models\ProjectAsset;

it('places a model at the origin in a new version', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create(['name' => 'Pump skid']);

    $version = resolve(AddAssetToTwin::class)->handle($twin, $user, $asset);

    expect($version?->number)->toBe(2)
        ->and($version?->note)->toBe('Added Pump skid')
        ->and($version?->definition['models'])->toBe([[
            'id' => 'upload-'.mb_substr($asset->id, -8),
            'name' => 'Pump skid',
            'url' => $asset->url(),
            'assetId' => $asset->id,
            'placement' => ['east' => 0, 'north' => 0, 'up' => 0, 'headingDeg' => 0, 'scale' => 1],
        ]]);
});

it('adds a tileset where its own georeference puts it', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create(['kind' => ProjectAssetKind::Tileset, 'entry' => 'tileset.json']);

    $layers = resolve(AddAssetToTwin::class)->handle($twin, $user, $asset)?->definition['tilesets'];

    expect($layers)->toHaveCount(1)
        ->and($layers[0])->not->toHaveKey('placement')
        ->and($layers[0]['url'])->toEndWith('/files/tileset.json');
});

it('saves nothing when the twin already shows the upload', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create();
    resolve(AddAssetToTwin::class)->handle($twin, $user, $asset);

    expect(resolve(AddAssetToTwin::class)->handle($twin, $user, $asset))->toBeNull()
        ->and($twin->versions()->count())->toBe(2);
});

it('refuses uploads from another project', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $elsewhere = ProjectAsset::factory()->create();

    expect(fn () => resolve(AddAssetToTwin::class)->handle($twin, $user, $elsewhere))
        ->toThrow(InvalidArgumentException::class, 'another project');
});

it('refuses uploads that are not ready yet', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->processing()->create();

    expect(fn () => resolve(AddAssetToTwin::class)->handle($twin, $user, $asset))
        ->toThrow(InvalidArgumentException::class, 'not ready');
});
