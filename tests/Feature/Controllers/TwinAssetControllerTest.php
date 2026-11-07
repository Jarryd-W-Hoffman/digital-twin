<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('uploads into the project library and adds the upload to the twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.assets.store', $twin), ['name' => 'Pump', 'file' => glbUpload()])
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pump uploaded.']);

    $asset = $twin->project->assets()->sole();
    expect($asset->name)->toBe('Pump')
        ->and($twin->latestVersion()->sole()->definition['models'][0]['assetId'])->toBe($asset->id);
});

it('refuses other file types', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.assets.store', $twin), ['name' => 'X', 'file' => UploadedFile::fake()->create('x.exe', 1)])
        ->assertSessionHasErrors('file');
});

it('keeps operators from uploading', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.assets.store', $twin), ['name' => 'P', 'file' => glbUpload()])->assertForbidden();
});
