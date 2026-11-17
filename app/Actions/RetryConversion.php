<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUpload;
use App\Models\ProjectAsset;
use App\Support\PointCloudOptions;
use Illuminate\Support\Arr;
use LogicException;

final readonly class RetryConversion
{
    /**
     * Queues a failed conversion again, from the original it kept,
     * optionally with new options (say, the EPSG code it was missing). What
     * the upload is stays as it was, as does whether it's an OpenDroneMap
     * export still to be split, or photos still to be processed (and how).
     *
     * @param  array{epsg?: int, spacing?: float, as?: string, y_up?: bool, quality?: string, dtm?: bool, captured_on?: string}  $options
     */
    public function handle(ProjectAsset $asset, array $options): void
    {
        throw_unless(
            $asset->kind->isConverted() && $asset->status === ProjectAssetStatus::Failed,
            LogicException::class,
            'Only a failed conversion can be retried.',
        );

        $asset->update(['status' => ProjectAssetStatus::Processing, 'options' => [...PointCloudOptions::forConversion($options), ...Arr::only($asset->options ?? [], ['opendronemap', 'photos', 'quality', 'dtm'])], 'progress' => 0, 'error' => null, 'log' => null]);

        dispatch(new ConvertUpload($asset));
    }
}
