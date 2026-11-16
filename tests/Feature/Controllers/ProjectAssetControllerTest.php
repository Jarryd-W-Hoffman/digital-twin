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
    $this->actingAs($user)->patch(route('projects.assets.update', [$project, $asset]), ['captured_on' => '2026-03-10'])->assertForbidden();
});

it('only finds uploads through their own project', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $elsewhere = ProjectAsset::factory()->create();

    $this->actingAs($user)->delete(route('projects.assets.destroy', [$project, $elsewhere]))->assertNotFound();
    $this->actingAs($user)->patch(route('projects.assets.update', [$project, $elsewhere]))->assertNotFound();
});

it('dates an upload by when it was captured, and undates it', function (): void {
    $this->travelTo('2026-11-15 09:00');
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->create(['name' => 'March scan']);

    $this->actingAs($user)->patch(route('projects.assets.update', [$project, $asset]), ['captured_on' => '2026-03-10'])
        ->assertRedirectToRoute('projects.show', $project)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'March scan dated 2026-03-10.']);
    expect($asset->refresh()->captured_on?->toDateString())->toBe('2026-03-10');

    $this->actingAs($user)->get(route('projects.show', $project))
        ->assertInertia(fn ($page) => $page->where('assets.0.capturedOn', '2026-03-10'));

    $this->actingAs($user)->patch(route('projects.assets.update', [$project, $asset]), ['captured_on' => ''])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'March scan is no longer dated.']);
    expect($asset->refresh()->captured_on)->toBeNull();
});

it('refuses capture dates in the future or not as dates', function (string $date): void {
    $this->travelTo('2026-11-15 09:00');
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->create();

    $this->actingAs($user)->patch(route('projects.assets.update', [$project, $asset]), ['captured_on' => $date])->assertSessionHasErrors('captured_on');
    $this->actingAs($user)->post(route('projects.assets.store', $project), ['name' => 'P', 'file' => glbUpload(), 'captured_on' => $date])->assertSessionHasErrors('captured_on');
})->with(['2026-11-16', '10/03/2026', 'March']);

it('keeps the capture date given at upload on the upload, not among its options', function (): void {
    Queue::fake();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), [
        'name' => 'Quay', 'file' => UploadedFile::fake()->createWithContent('quay.las', 'LASF'), 'epsg' => '2193', 'captured_on' => '2026-03-10',
    ])->assertSessionHasNoErrors();

    expect($project->assets()->sole())
        ->options->toBe(['epsg' => 2193])
        ->captured_on->toDateString()->toBe('2026-03-10');
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

it('takes map layers and imagery, and says they are converting', function (string $file, ?string $as, ProjectAssetKind $kind, string $message): void {
    Queue::fake();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), array_filter([
        'name' => 'Site', 'file' => UploadedFile::fake()->createWithContent($file, 'bytes'), 'epsg' => '2193', 'as' => $as,
    ]))->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);

    expect($project->assets()->sole()->kind)->toBe($kind)
        ->and($project->assets()->sole()->options)->toBe(['epsg' => 2193]);
})->with([
    'a DXF plan' => ['plan.dxf', null, ProjectAssetKind::Vector, 'Site uploaded. Converting it to a map layer…'],
    'a zipped shapefile' => ['parcels.zip', 'vector', ProjectAssetKind::Vector, 'Site uploaded. Converting it to a map layer…'],
    'an orthophoto' => ['ortho.tif', 'imagery', ProjectAssetKind::Imagery, 'Site uploaded. Tiling the image…'],
    'a photogrammetry export' => ['survey.zip', 'mesh', ProjectAssetKind::Mesh, 'Site uploaded. Converting it to 3D Tiles…'],
    'an OBJ mesh' => ['survey.obj', null, ProjectAssetKind::Mesh, 'Site uploaded. Converting it to 3D Tiles…'],
]);

it('takes an OpenDroneMap export as a mesh to split, and says so', function (): void {
    Queue::fake();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), [
        'name' => 'Aukerman Park', 'file' => UploadedFile::fake()->createWithContent('all.zip', 'PK'), 'as' => 'opendronemap',
    ])->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Aukerman Park uploaded. Splitting the OpenDroneMap export into library items…']);

    expect($project->assets()->sole()->kind)->toBe(ProjectAssetKind::Mesh)
        ->and($project->assets()->sole()->options)->toBe(['opendronemap' => true]);
});

it('keeps whether a mesh in local metres has y up', function (): void {
    Queue::fake();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), [
        'name' => 'Skid', 'file' => UploadedFile::fake()->createWithContent('skid.obj', 'v 0 0 0'), 'y_up' => '1',
    ])->assertSessionHasNoErrors();

    expect($project->assets()->sole()->options)->toBe(['y_up' => true]);
});

it('refuses to take a file as anything but imagery or a map layer', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.assets.store', $project), [
        'name' => 'Site', 'file' => UploadedFile::fake()->createWithContent('ortho.tif', 'bytes'), 'as' => 'terrain',
    ])->assertSessionHasErrors('as');
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

    $this->actingAs($user)->post(route('projects.assets.conversion.store', [$project, $asset]), ['epsg' => 2193, 'captured_on' => '2026-03-10'])
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
