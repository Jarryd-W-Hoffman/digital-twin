<?php

declare(strict_types=1);

use App\Actions\AbortDirectUpload;
use App\Actions\CompleteDirectUpload;
use App\Actions\SignDirectUploadParts;
use App\Actions\StartDirectUpload;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUploadedPointCloud;
use App\Jobs\ProcessDirectUpload;
use App\Models\Project;
use App\Models\ProjectAsset;
use Aws\Result;
use Illuminate\Support\Facades\Queue;

it('adds the upload to the library as uploading and plans its parts', function (): void {
    $sent = fakeDirectUploads(['CreateMultipartUpload' => new Result(['UploadId' => 'up-9'])], partBytes: 10);
    $project = Project::factory()->create();

    $started = resolve(StartDirectUpload::class)->handle($project, 'Quay scan', 'Quay Scan.ZIP', 25);
    $asset = $started['asset']->refresh();

    expect($started['partBytes'])->toBe(10)
        ->and($started['parts'])->toBe(3)
        ->and($asset->status)->toBe(ProjectAssetStatus::Uploading)
        ->and($asset->kind)->toBe(ProjectAssetKind::Tileset)
        ->and($asset->upload_id)->toBe('up-9')
        ->and($asset->bytes)->toBe(25)
        ->and($asset->directory)->toBe($project->storagePrefix().'/assets/'.$asset->id)
        ->and($sent[0]['Key'])->toBe('uploads/'.$asset->id.'/Quay Scan.ZIP');
});

it('signs the parts asked for', function (): void {
    fakeDirectUploads();
    $asset = ProjectAsset::factory()->uploading()->create();

    $urls = resolve(SignDirectUploadParts::class)->handle($asset, [1, 2]);

    expect(array_keys($urls))->toBe([1, 2])
        ->and($urls[2])->toContain('partNumber=2');
});

it('completes the upload and queues its checks', function (): void {
    Queue::fake();
    $sent = fakeDirectUploads();
    $asset = ProjectAsset::factory()->uploading()->create();

    resolve(CompleteDirectUpload::class)->handle($asset, [['PartNumber' => 1, 'ETag' => '"a"']]);

    expect($sent[0]->getName())->toBe('CompleteMultipartUpload')
        ->and($asset->refresh()->status)->toBe(ProjectAssetStatus::Processing)
        ->and($asset->upload_id)->toBeNull();
    Queue::assertPushed(ProcessDirectUpload::class, fn (ProcessDirectUpload $job): bool => $job->asset->is($asset));
});

it('aborts an upload and drops it from the library', function (): void {
    $sent = fakeDirectUploads();
    $asset = ProjectAsset::factory()->uploading('up-3')->create();

    resolve(AbortDirectUpload::class)->handle($asset);

    expect($sent[0]->getName())->toBe('AbortMultipartUpload')
        ->and($sent[0]['UploadId'])->toBe('up-3')
        ->and($asset->fresh())->toBeNull();
});

it('drops an upload that never opened without calling the store', function (): void {
    $sent = fakeDirectUploads();
    $asset = ProjectAsset::factory()->uploading()->create(['upload_id' => null]);

    resolve(AbortDirectUpload::class)->handle($asset);

    expect($sent)->toHaveCount(0)->and($asset->fresh())->toBeNull();
});

it('only works on uploads still open', function (string $action, array $arguments): void {
    fakeDirectUploads();
    $asset = ProjectAsset::factory()->create();

    resolve($action)->handle($asset, ...$arguments);
})->with([
    [SignDirectUploadParts::class, [[1]]],
    [CompleteDirectUpload::class, [[['PartNumber' => 1, 'ETag' => 'e']]]],
    [AbortDirectUpload::class, []],
])->throws(LogicException::class, 'not open');

it('queues a directly uploaded point cloud for conversion instead', function (): void {
    Queue::fake();
    fakeDirectUploads();
    $asset = ProjectAsset::factory()->uploading()->create(['kind' => ProjectAssetKind::PointCloud, 'original_name' => 'scan.e57']);

    resolve(CompleteDirectUpload::class)->handle($asset, [['PartNumber' => 1, 'ETag' => '"a"']]);

    Queue::assertPushed(ConvertUploadedPointCloud::class);
    Queue::assertNotPushed(ProcessDirectUpload::class);
});

it('keeps conversion options for a point cloud only', function (): void {
    fakeDirectUploads(['CreateMultipartUpload' => new Result(['UploadId' => 'u'])]);
    $project = Project::factory()->create();

    $cloud = resolve(StartDirectUpload::class)->handle($project, 'Scan', 'scan.e57', 10, ['spacing' => 0.1])['asset'];
    $model = resolve(StartDirectUpload::class)->handle($project, 'Pump', 'pump.glb', 10, ['spacing' => 0.1])['asset'];

    expect($cloud->kind)->toBe(ProjectAssetKind::PointCloud)
        ->and($cloud->refresh()->options)->toBe(['spacing' => 0.1])
        ->and($model->refresh()->options)->toBeNull();
});
