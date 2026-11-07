<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUploadedPointCloud;
use App\Models\ProjectAsset;
use LogicException;

final readonly class RetryPointCloudConversion
{
    /**
     * Queues a failed point cloud for conversion again, from the original it
     * kept, optionally with new options (say, the EPSG code it was missing).
     *
     * @param  array{epsg?: int, spacing?: float}  $options
     */
    public function handle(ProjectAsset $asset, array $options): void
    {
        throw_unless(
            $asset->kind === ProjectAssetKind::PointCloud && $asset->status === ProjectAssetStatus::Failed,
            LogicException::class,
            'Only a failed point cloud conversion can be retried.',
        );

        $asset->update(['status' => ProjectAssetStatus::Processing, 'options' => $options, 'progress' => 0, 'error' => null, 'log' => null]);

        dispatch(new ConvertUploadedPointCloud($asset));
    }
}
