<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Actions\SaveTwinVersion;
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

it('adds map layers and imagery where their coordinates say', function (ProjectAssetKind $kind, string $entry, string $field): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create(['kind' => $kind, 'entry' => $entry, 'name' => 'Parcels']);

    $definition = resolve(AddAssetToTwin::class)->handle($twin, $user, $asset)?->definition;

    expect($definition[$field])->toBe([[
        'id' => 'upload-'.mb_substr($asset->id, -8),
        'name' => 'Parcels',
        'url' => $asset->url(),
        'assetId' => $asset->id,
    ]])->and($definition[$field][0]['url'])->toEndWith('/files/'.$entry);
})->with([
    [ProjectAssetKind::Vector, 'layer.geojson', 'vectors'],
    [ProjectAssetKind::Imagery, 'imagery.json', 'imagery'],
]);

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

it('adds a georeferenced point cloud as a tileset where it belongs', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->pointCloud('EPSG:2193')->create();

    $layers = resolve(AddAssetToTwin::class)->handle($twin, $user, $asset)?->definition['tilesets'];

    expect($layers[0])->not->toHaveKey('placement')
        ->and($layers[0]['assetId'])->toBe($asset->id);
});

it('places a point cloud in local metres at the origin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->pointCloud(null)->create();

    $layers = resolve(AddAssetToTwin::class)->handle($twin, $user, $asset)?->definition['tilesets'];

    expect($layers[0]['placement'])->toBe(['east' => 0, 'north' => 0, 'up' => 0, 'headingDeg' => 0, 'scale' => 1]);
});

it('adds a georeferenced mesh as a tileset where it belongs, and places one in local metres at the origin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $georeferenced = ProjectAsset::factory()->for($twin->project)->mesh(['lon' => 174.767, 'lat' => -36.848, 'height' => 33.0, 'source' => 'metadata.xml'])->create();
    $local = ProjectAsset::factory()->for($twin->project)->mesh()->create(['name' => 'Pump skid scan']);

    resolve(AddAssetToTwin::class)->handle($twin, $user, $georeferenced);
    $layers = resolve(AddAssetToTwin::class)->handle($twin, $user, $local)?->definition['tilesets'];

    expect($layers[0])->not->toHaveKey('placement')
        ->and($layers[0]['assetId'])->toBe($georeferenced->id)
        ->and($layers[1]['placement'])->toBe(['east' => 0, 'north' => 0, 'up' => 0, 'headingDeg' => 0, 'scale' => 1]);
});

it("adds terrain to the twin's terrains, once each", function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $first = ProjectAsset::factory()->for($twin->project)->create(['kind' => ProjectAssetKind::Terrain, 'entry' => 'layer.json', 'name' => 'Lidar DEM']);
    $second = ProjectAsset::factory()->for($twin->project)->create(['kind' => ProjectAssetKind::Terrain, 'entry' => 'layer.json', 'name' => 'Survey DEM']);
    $action = resolve(AddAssetToTwin::class);
    $definition = $twin->latestVersion()->sole()->definition;
    unset($definition['terrains']);
    resolve(SaveTwinVersion::class)->handle($twin, $user, $definition);

    $version = $action->handle($twin, $user, $first);

    expect($version?->note)->toBe('Set the terrain to Lidar DEM')
        ->and($version?->definition['terrains'])->toBe([['name' => 'Lidar DEM', 'url' => $first->url(), 'assetId' => $first->id]])
        ->and($version?->definition)->not->toHaveKey('tilesets')
        ->and($action->handle($twin, $user, $first))->toBeNull();

    $version = $action->handle($twin, $user, $second);

    expect($version?->note)->toBe('Added Survey DEM as another terrain')
        ->and(array_column($version?->definition['terrains'] ?? [], 'assetId'))->toBe([$first->id, $second->id]);
});

it('places an IFC model where its georeference puts it, near the twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    ['lon' => $lon, 'lat' => $lat] = $twin->latestVersion()->sole()->definition['origin'];
    // 120 m east and 80 m north of the twin's origin.
    $georeference = [
        'lon' => $lon + rad2deg(120 / (6_378_137 * cos(deg2rad($lat)))),
        'lat' => $lat + rad2deg(80 / 6_378_137),
        'headingDeg' => -30.0,
        'source' => 'IfcMapConversion',
    ];
    $asset = ProjectAsset::factory()->for($twin->project)->bim($georeference)->create();

    $models = resolve(AddAssetToTwin::class)->handle($twin, $user, $asset)?->definition['models'];

    expect($models[0]['placement'])->toEqual(['east' => 120, 'north' => 80, 'up' => 0, 'headingDeg' => -30.0, 'scale' => 1])
        ->and($models[0]['url'])->toEndWith('/files/model.glb');
});

it('starts an IFC model at the origin when it is far away or not georeferenced', function (?array $georeference): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->bim($georeference)->create();

    $models = resolve(AddAssetToTwin::class)->handle($twin, $user, $asset)?->definition['models'];

    expect($models[0]['placement'])->toBe(['east' => 0, 'north' => 0, 'up' => 0, 'headingDeg' => 0, 'scale' => 1]);
})->with([
    'not georeferenced' => [null],
    'on another continent' => [['lon' => -0.1276, 'lat' => 51.5072, 'headingDeg' => 0.0, 'source' => 'IfcSite']],
]);

it('starts an IFC model at the origin when the twin has none', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $version = $twin->latestVersion()->sole();
    $definition = $version->definition;
    unset($definition['origin']);
    $version->update(['definition' => $definition]);
    $asset = ProjectAsset::factory()->for($twin->project)->bim(['lon' => 174.7, 'lat' => -36.8, 'headingDeg' => 0.0, 'source' => 'IfcSite'])->create();

    $models = resolve(AddAssetToTwin::class)->handle($twin, $user, $asset)?->definition['models'];

    expect($models[0]['placement']['east'])->toBe(0);
});
