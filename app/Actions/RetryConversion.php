<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUpload;
use App\Models\ProjectAsset;
use LogicException;

final readonly class RetryConversion
{
    /**
     * Queues a failed point cloud or DEM for conversion again, from the
     * original it kept, optionally with new options (say, the EPSG code it
     * was missing).
     *
     * @param  array{epsg?: int, spacing?: float}  $options
     */
    public function handle(ProjectAsset $asset, array $options): void
    {
        throw_unless(
            $asset->kind->isConverted() && $asset->status === ProjectAssetStatus::Failed,
            LogicException::class,
            'Only a failed conversion can be retried.',
        );

        $asset->update(['status' => ProjectAssetStatus::Processing, 'options' => $options, 'progress' => 0, 'error' => null, 'log' => null]);

        dispatch(new ConvertUpload($asset));
    }
}
