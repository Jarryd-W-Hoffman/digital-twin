<?php

declare(strict_types=1);

use App\Actions\StoreTwinAsset;
use App\Enums\OrganisationRole;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('serves tileset files with fixed types and a locked-down policy', function (string $path, string $type): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $asset = resolve(StoreTwinAsset::class)->handle($twin, $user, tilesetZip([
        'tileset.json' => tilesetJson(), 'tiles/0.glb' => 'g', 'tiles/1.b3dm' => 'b', 'tex/a.png' => 'p',
    ]), 'Scan');

    $response = $this->actingAs($user)->get(route('twins.assets.files.show', [$twin, $asset, $path]));

    $response->assertOk()
        ->assertHeader('Content-Type', $type)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->headers->get('Cache-Control'))->toContain('private');
})->with([
    ['tileset.json', 'application/json'],
    ['tiles/0.glb', 'model/gltf-binary'],
    ['tiles/1.b3dm', 'application/octet-stream'],
    ['tex/a.png', 'image/png'],
]);

it('serves nothing outside the asset', function (string $path): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = resolve(StoreTwinAsset::class)->handle($twin, $user, glbUpload(), 'Pump');
    Storage::disk('local')->put($twin->storagePrefix().'/secret.json', '{}');

    $this->actingAs($user)->get(route('twins.assets.files.show', [$twin, $asset, $path]))->assertNotFound();
})->with(['missing.glb', '../../secret.json', 'a//b.glb', './model.glb']);

it('hides files from outsiders', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = resolve(StoreTwinAsset::class)->handle($twin, $user, glbUpload(), 'Pump');
    [$outsider] = member();

    $this->actingAs($outsider)->get($asset->url())->assertForbidden();
});
