<?php

declare(strict_types=1);

use App\Actions\StoreTwinAsset;
use App\Enums\OrganisationRole;
use App\Models\TwinAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('uploads a model', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.assets.store', $twin), ['name' => 'Pump', 'file' => glbUpload()])
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pump uploaded.']);

    expect($twin->assets()->sole()->name)->toBe('Pump');
});

it('uploads a tileset', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.assets.store', $twin), ['name' => 'Scan', 'file' => tilesetZip(['tileset.json' => tilesetJson()])])
        ->assertRedirectToRoute('twins.show', $twin);

    expect($twin->assets()->sole()->entry)->toBe('tileset.json');
});

it('refuses other file types and bad archives', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.assets.store', $twin), ['name' => 'X', 'file' => UploadedFile::fake()->create('x.exe', 1)])
        ->assertSessionHasErrors('file');
    $this->actingAs($user)->post(route('twins.assets.store', $twin), ['name' => 'X', 'file' => tilesetZip(['../x.json' => '{}'])])
        ->assertSessionHasErrors(['file' => 'The zip contains an unsafe path: ../x.json']);
});

it('removes an upload', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = resolve(StoreTwinAsset::class)->handle($twin, $user, glbUpload(), 'Pump');

    $this->actingAs($user)->delete(route('twins.assets.destroy', [$twin, $asset]))
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pump removed.']);

    expect(TwinAsset::query()->count())->toBe(0);
});

it('keeps operators from uploading or removing', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);
    $asset = TwinAsset::factory()->for($twin)->create();

    $this->actingAs($user)->post(route('twins.assets.store', $twin), ['name' => 'P', 'file' => glbUpload()])->assertForbidden();
    $this->actingAs($user)->delete(route('twins.assets.destroy', [$twin, $asset]))->assertForbidden();
});

it('only finds uploads through their own twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $elsewhere = TwinAsset::factory()->create();

    $this->actingAs($user)->delete(route('twins.assets.destroy', [$twin, $elsewhere]))->assertNotFound();
});
