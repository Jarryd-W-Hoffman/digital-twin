<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetStatus;
use App\Models\ProjectAsset;
use App\Support\DirectUploads;
use LogicException;

final readonly class AbortDirectUpload
{
    public function __construct(private DirectUploads $uploads) {}

    /**
     * Gives up an upload still in progress: its parts are discarded and it
     * leaves the library.
     */
    public function handle(ProjectAsset $asset): void
    {
        throw_if($asset->status !== ProjectAssetStatus::Uploading, LogicException::class, 'The upload is not open.');

        if ($asset->upload_id !== null) {
            $this->uploads->abort($asset->sourceKey(), $asset->upload_id);
        }

        $asset->delete();
    }
}
