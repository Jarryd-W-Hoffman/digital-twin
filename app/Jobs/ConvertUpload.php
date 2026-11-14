<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ConvertIfc;
use App\Actions\ConvertImagery;
use App\Actions\ConvertPointCloud;
use App\Actions\ConvertTerrain;
use App\Actions\ConvertVector;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Converts a library upload (a point cloud, DEM, IFC model, map layer or
 * image) on the "conversions" queue, which only the converter (with PDAL,
 * py3dtiles, GDAL and Meridian's scripts) works. It
 * is tried once: a failure is shown on the library item, which can be
 * retried from there.
 */
#[Timeout(14400)]
#[Tries(1)]
final class ConvertUpload implements ShouldQueue
{
    use Queueable;

    public function __construct(public ProjectAsset $asset)
    {
        $this->onConnection('conversions')->onQueue('conversions');
    }

    public function handle(ConvertPointCloud $pointCloud, ConvertTerrain $terrain, ConvertIfc $ifc, ConvertVector $vector, ConvertImagery $imagery): void
    {
        try {
            match ($this->asset->kind) {
                ProjectAssetKind::Terrain => $terrain->handle($this->asset),
                ProjectAssetKind::Bim => $ifc->handle($this->asset),
                ProjectAssetKind::Vector => $vector->handle($this->asset),
                ProjectAssetKind::Imagery => $imagery->handle($this->asset),
                default => $pointCloud->handle($this->asset),
            };
        } catch (ConversionFailed $conversionFailed) {
            $this->asset->update(['status' => ProjectAssetStatus::Failed, 'error' => $conversionFailed->getMessage()]);
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
