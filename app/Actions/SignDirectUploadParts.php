<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetStatus;
use App\Models\ProjectAsset;
use App\Support\DirectUploads;
use LogicException;

final readonly class SignDirectUploadParts
{
    public function __construct(private DirectUploads $uploads) {}

    /**
     * Presigned PUT URLs for the given part numbers, valid for an hour.
     *
     * @param  list<int>  $parts
     * @return array<int, string> Part number to URL.
     */
    public function handle(ProjectAsset $asset, array $parts): array
    {
        throw_if($asset->status !== ProjectAssetStatus::Uploading || $asset->upload_id === null, LogicException::class, 'The upload is not open.');

        $urls = [];

        foreach ($parts as $part) {
            $urls[$part] = $this->uploads->signPart($asset->sourceKey(), $asset->upload_id, $part);
        }

        return $urls;
    }
}
