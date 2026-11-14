<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Enums\ProjectAssetStatus;
use App\Jobs\ProcessDirectUpload;
use App\Models\Project;
use App\Models\ProjectAsset;
use Aws\Result;
use Illuminate\Support\Facades\Queue;

it('starts a direct upload and plans its parts', function (): void {
    fakeDirectUploads(['CreateMultipartUpload' => new Result(['UploadId' => 'up-1'])], partBytes: 1024);
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $response = $this->actingAs($user)->postJson(route('projects.uploads.store', $project), ['name' => 'Scan', 'filename' => 'scan.zip', 'bytes' => 3000]);

    $asset = $project->assets()->sole();
    $response->assertCreated()->assertExactJson(['id' => $asset->id, 'partBytes' => 1024, 'parts' => 3]);
    expect($asset->status)->toBe(ProjectAssetStatus::Uploading);
});

it('is not there unless direct uploads are on', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->postJson(route('projects.uploads.store', $project), ['name' => 'Scan', 'filename' => 'scan.zip', 'bytes' => 3000])
        ->assertNotFound();
});

it('checks what is about to be uploaded', function (): void {
    fakeDirectUploads();
    config(['meridian.direct_uploads.max_upload_gb' => 1]);
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->postJson(route('projects.uploads.store', $project), ['name' => '', 'filename' => '../evil.exe', 'bytes' => 2 * 1024 ** 3])
        ->assertJsonValidationErrors(['name', 'bytes', 'filename' => 'Upload a .glb model, a zipped 3D Tiles tileset or shapefile, a LAS, LAZ or E57 point cloud, a GeoTIFF or ASCII grid, an IFC model, or a GeoJSON, KML, KMZ, DXF or GeoPackage map layer.']);
});

it('signs parts, completes the upload and queues its checks', function (): void {
    Queue::fake();
    fakeDirectUploads();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->uploading()->create();

    $this->actingAs($user)->postJson(route('projects.uploads.parts', [$project, $asset]), ['parts' => [1, '2']])
        ->assertOk()
        ->assertJsonStructure(['urls' => ['1', '2']]);
    $this->actingAs($user)->postJson(route('projects.uploads.complete', [$project, $asset]), ['parts' => [['number' => 2, 'etag' => '"b"'], ['number' => 1, 'etag' => '"a"']]])
        ->assertOk()
        ->assertExactJson(['status' => 'processing']);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Processing);
    Queue::assertPushed(ProcessDirectUpload::class);
});

it('checks the parts it is given', function (): void {
    fakeDirectUploads();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->uploading()->create();

    $this->actingAs($user)->postJson(route('projects.uploads.parts', [$project, $asset]), ['parts' => [0]])->assertJsonValidationErrors('parts.0');
    $this->actingAs($user)->postJson(route('projects.uploads.complete', [$project, $asset]), ['parts' => [['number' => 1, 'etag' => 'a'], ['number' => 1, 'etag' => 'b']]])
        ->assertJsonValidationErrors('parts.0.number');
});

it('gives up an upload', function (): void {
    fakeDirectUploads();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->uploading()->create();

    $this->actingAs($user)->deleteJson(route('projects.uploads.destroy', [$project, $asset]))->assertNoContent();

    expect($asset->fresh())->toBeNull();
});

it('sends the page back after giving up an abandoned upload', function (): void {
    fakeDirectUploads();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->uploading()->create();

    $this->actingAs($user)->from(route('projects.show', $project))->delete(route('projects.uploads.destroy', [$project, $asset]))
        ->assertRedirect(route('projects.show', $project));
});

it('answers a conflict for uploads no longer open', function (): void {
    fakeDirectUploads();
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->processing()->create();

    $this->actingAs($user)->postJson(route('projects.uploads.parts', [$project, $asset]), ['parts' => [1]])->assertConflict();
    $this->actingAs($user)->postJson(route('projects.uploads.complete', [$project, $asset]), ['parts' => [['number' => 1, 'etag' => 'a']]])->assertConflict();
    $this->actingAs($user)->deleteJson(route('projects.uploads.destroy', [$project, $asset]))->assertConflict();
});

it('keeps operators out, and uploads within their own project', function (): void {
    fakeDirectUploads();
    [$operator, $organisation] = member(OrganisationRole::Operator);
    $project = Project::factory()->for($organisation)->create();
    $asset = ProjectAsset::factory()->for($project)->uploading()->create();
    [$owner, $other] = member();
    $elsewhere = Project::factory()->for($other)->create();

    $this->actingAs($operator)->postJson(route('projects.uploads.store', $project), ['name' => 'S', 'filename' => 's.glb', 'bytes' => 1])->assertForbidden();
    $this->actingAs($operator)->postJson(route('projects.uploads.parts', [$project, $asset]), ['parts' => [1]])->assertForbidden();
    $this->actingAs($operator)->postJson(route('projects.uploads.complete', [$project, $asset]), ['parts' => [['number' => 1, 'etag' => 'a']]])->assertForbidden();
    $this->actingAs($operator)->deleteJson(route('projects.uploads.destroy', [$project, $asset]))->assertForbidden();
    $this->actingAs($owner)->postJson(route('projects.uploads.parts', [$elsewhere, $asset]), ['parts' => [1]])->assertNotFound();
});
