<?php

declare(strict_types=1);

use App\Actions\StoreTwinAsset;
use App\Enums\TwinAssetKind;
use App\Models\Twin;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Storage::fake('local'));

it('stores a model and places it at the origin in a new version', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $asset = resolve(StoreTwinAsset::class)->handle($twin, $user, glbUpload(), 'Pump skid');
    $definition = $twin->latestVersion()->sole()->definition;

    expect($asset->kind)->toBe(TwinAssetKind::Model)
        ->and($asset->entry)->toBe('model.glb')
        ->and($asset->bytes)->toBe(12)
        ->and($asset->original_name)->toBe('pump.glb')
        ->and(Storage::disk('local')->exists($asset->directory().'/model.glb'))->toBeTrue()
        ->and($twin->latestVersion()->sole()->note)->toBe('Added Pump skid')
        ->and($definition['models'])->toBe([[
            'id' => 'upload-'.mb_substr($asset->id, -8),
            'name' => 'Pump skid',
            'url' => $asset->url(),
            'assetId' => $asset->id,
            'placement' => ['east' => 0, 'north' => 0, 'up' => 0, 'headingDeg' => 0, 'scale' => 1],
        ]]);
});

it('stores a tileset where its own georeference puts it', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $asset = resolve(StoreTwinAsset::class)->handle($twin, $user, tilesetZip(['tileset.json' => tilesetJson(), 'tiles/0.glb' => 'x']), 'Laser scan');
    $layer = $twin->latestVersion()->sole()->definition['tilesets'][0];

    expect($asset->kind)->toBe(TwinAssetKind::Tileset)
        ->and($asset->files)->toBe(2)
        ->and($layer)->not->toHaveKey('placement')
        ->and($layer['url'])->toEndWith('/files/tileset.json');
});

it('refuses files that are not GLB', function (string $bytes): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    expect(fn () => resolve(StoreTwinAsset::class)->handle($twin, $user, glbUpload(bytes: $bytes), 'Fake'))
        ->toThrow(ValidationException::class, 'not a glTF 2.0 binary')
        ->and($twin->assets()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'short' => ['glTF'],
    'wrong magic' => ['GLTF'.pack('VV', 2, 12)],
    'version 1' => ['glTF'.pack('VV', 1, 12)],
    'wrong length' => ['glTF'.pack('VV', 2, 99)],
]);

it('cleans up the files when saving fails', function (): void {
    [$user, $organisation] = member();
    $twin = Twin::factory()->for($organisation)->create();

    expect(fn () => resolve(StoreTwinAsset::class)->handle($twin, $user, glbUpload(), 'Pump'))->toThrow(ModelNotFoundException::class)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($twin->assets()->count())->toBe(0);
});
