<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Enums\OrganisationRole;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUpload;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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

it('takes point clouds with conversion options and says they are converting', function (): void {
    Queue::fake();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), [
        'name' => 'Quay', 'file' => UploadedFile::fake()->createWithContent('quay.las', 'LASF'), 'epsg' => '2193', 'spacing' => '0.05',
    ])->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Quay uploaded. Converting it to 3D Tiles…']);

    expect($project->assets()->sole()->options)->toBe(['epsg' => 2193, 'spacing' => 0.05]);
});

it('takes DEMs and says they are converting to terrain', function (): void {
    Queue::fake();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), [
        'name' => 'Ground', 'file' => UploadedFile::fake()->createWithContent('ground.tif', "II*\0"), 'epsg' => '2193',
    ])->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Ground uploaded. Converting it to terrain…']);

    expect($project->assets()->sole()->kind)->toBe(ProjectAssetKind::Terrain);
});

it('takes IFC models and says they are converting to a model', function (): void {
    Queue::fake();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), [
        'name' => 'Shed', 'file' => UploadedFile::fake()->createWithContent('shed.ifc', "ISO-10303-21;\n"),
    ])->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Shed uploaded. Converting it to a model…']);

    expect($project->assets()->sole()->kind)->toBe(ProjectAssetKind::Bim);
    Queue::assertPushed(ConvertUpload::class);
});

it('checks conversion options', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), [
        'name' => 'Quay', 'file' => UploadedFile::fake()->createWithContent('quay.las', 'LASF'), 'epsg' => '12', 'spacing' => '0',
    ])->assertSessionHasErrors(['epsg', 'spacing']);
});

it('retries a failed conversion', function (): void {
    Queue::fake();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->pointCloud(null)->create(['status' => ProjectAssetStatus::Failed]);

    $this->actingAs($user)->post(route('projects.assets.conversion.store', [$project, $asset]), ['epsg' => 2193])
        ->assertRedirectToRoute('projects.show', $project)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Converting '.$asset->name.' again.']);

    expect($asset->refresh()->options)->toBe(['epsg' => 2193]);
    Queue::assertPushed(ConvertUpload::class);
});

it('only retries failed point clouds, for those who can change the project', function (): void {
    [$user, $organisation] = member();
    $ready = ProjectAsset::factory()->for(Project::factory()->for($organisation))->pointCloud()->create();
    [$operator, $operated] = member(OrganisationRole::Operator);
    $project = Project::factory()->for($operated)->create();
    $failed = ProjectAsset::factory()->for($project)->pointCloud()->create(['status' => ProjectAssetStatus::Failed]);

    $this->actingAs($user)->post(route('projects.assets.conversion.store', [$ready->project, $ready]))->assertConflict();
    $this->actingAs($operator)->post(route('projects.assets.conversion.store', [$project, $failed]))->assertForbidden();
});
