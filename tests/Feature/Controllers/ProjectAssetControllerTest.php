<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Enums\OrganisationRole;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('uploads models and tilesets into the library', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), ['name' => 'Pump', 'file' => glbUpload()])
        ->assertRedirectToRoute('projects.show', $project)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pump uploaded.']);
    $this->actingAs($user)->post(route('projects.assets.store', $project), ['name' => 'Scan', 'file' => tilesetZip(['tileset.json' => tilesetJson()])]);

    expect($project->assets()->pluck('name')->all())->toEqualCanonicalizing(['Pump', 'Scan']);
});

it('refuses other file types and bad archives', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), ['name' => 'X', 'file' => UploadedFile::fake()->create('x.exe', 1)])
        ->assertSessionHasErrors('file');
    $this->actingAs($user)->post(route('projects.assets.store', $project), ['name' => 'X', 'file' => tilesetZip(['../x.json' => '{}'])])
        ->assertSessionHasErrors(['file' => 'The zip contains an unsafe path: ../x.json']);
});

it('deletes an upload and takes it out of the twins showing it', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create(['name' => 'Pump']);
    resolve(AddAssetToTwin::class)->handle($twin, $user, $asset);

    $this->actingAs($user)->delete(route('projects.assets.destroy', [$twin->project, $asset]))
        ->assertRedirectToRoute('projects.show', $twin->project)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pump deleted.']);

    expect(ProjectAsset::query()->count())->toBe(0)
        ->and($twin->latestVersion()->sole()->definition['models'])->toBe([]);
});

it('keeps operators from uploading or deleting', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), ['name' => 'P', 'file' => glbUpload()])->assertForbidden();
    $this->actingAs($user)->delete(route('projects.assets.destroy', [$project, $asset]))->assertForbidden();
});

it('only finds uploads through their own project', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $elsewhere = ProjectAsset::factory()->create();

    $this->actingAs($user)->delete(route('projects.assets.destroy', [$project, $elsewhere]))->assertNotFound();
});
