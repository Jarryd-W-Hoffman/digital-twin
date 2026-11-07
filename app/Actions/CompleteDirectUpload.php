<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUpload;
use App\Jobs\ProcessDirectUpload;
use App\Models\ProjectAsset;
use App\Support\DirectUploads;
use LogicException;

final readonly class CompleteDirectUpload
{
    public function __construct(private DirectUploads $uploads) {}

    /**
     * Joins the uploaded parts into one object and queues its checks, or its
     * conversion for a point cloud or DEM.
     *
     * @param  list<array{PartNumber: int, ETag: string}>  $parts
     */
    public function handle(ProjectAsset $asset, array $parts): void
    {
        throw_if($asset->status !== ProjectAssetStatus::Uploading || $asset->upload_id === null, LogicException::class, 'The upload is not open.');

        $this->uploads->complete($asset->sourceKey(), $asset->upload_id, $parts);
        $asset->update(['status' => ProjectAssetStatus::Processing, 'upload_id' => null]);

        dispatch($asset->kind->isConverted() ? new ConvertUpload($asset) : new ProcessDirectUpload($asset));
    }
}
