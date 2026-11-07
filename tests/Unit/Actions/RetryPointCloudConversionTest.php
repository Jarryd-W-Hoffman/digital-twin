<?php

declare(strict_types=1);

use App\Actions\RetryPointCloudConversion;
use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUploadedPointCloud;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\Queue;

it('queues a failed conversion again with new options', function (): void {
    Queue::fake();
    $asset = ProjectAsset::factory()->pointCloud(null)->create(['status' => ProjectAssetStatus::Failed, 'error' => 'No CRS', 'log' => 'old', 'progress' => 15]);

    resolve(RetryPointCloudConversion::class)->handle($asset, ['epsg' => 2193]);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Processing)
        ->and($asset->options)->toBe(['epsg' => 2193])
        ->and($asset->error)->toBeNull()
        ->and($asset->log)->toBeNull()
        ->and($asset->progress)->toBe(0);
    Queue::assertPushed(ConvertUploadedPointCloud::class);
});

it('only retries failed point clouds', function (ProjectAsset $asset): void {
    resolve(RetryPointCloudConversion::class)->handle($asset, []);
})->with([
    'a ready point cloud' => fn () => ProjectAsset::factory()->pointCloud()->create(),
    'a failed model' => fn () => ProjectAsset::factory()->create(['status' => ProjectAssetStatus::Failed]),
])->throws(LogicException::class, 'Only a failed point cloud conversion can be retried.');
