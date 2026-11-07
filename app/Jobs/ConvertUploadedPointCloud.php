<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ConvertPointCloud;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\PointCloudConversionFailed;
use App\Models\ProjectAsset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Converts a library point cloud on the "conversions" queue, which only the
 * converter (with PDAL and py3dtiles) works. It is tried once: a failure is
 * shown on the library item, which can be retried from there.
 */
#[Timeout(14400)]
#[Tries(1)]
final class ConvertUploadedPointCloud implements ShouldQueue
{
    use Queueable;

    public function __construct(public ProjectAsset $asset)
    {
        $this->onConnection('conversions')->onQueue('conversions');
    }

    public function handle(ConvertPointCloud $convert): void
    {
        try {
            $convert->handle($this->asset);
        } catch (PointCloudConversionFailed $pointCloudConversionFailed) {
            $this->asset->update(['status' => ProjectAssetStatus::Failed, 'error' => $pointCloudConversionFailed->getMessage()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->asset->update([
            'status' => ProjectAssetStatus::Failed,
            'error' => __('The conversion stopped unexpectedly. Retry it, or upload the file again.'),
        ]);
    }
}
