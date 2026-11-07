<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Support\DirectUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class StartDirectUpload
{
    public function __construct(private DirectUploads $uploads) {}

    /**
     * Adds the upload to the library as uploading and opens a multipart upload
     * for its bytes. The caller learns how to split the file.
     *
     * @return array{asset: ProjectAsset, partBytes: int, parts: int}
     */
    public function handle(Project $project, string $name, string $filename, int $bytes): array
    {
        return DB::transaction(function () use ($project, $name, $filename, $bytes): array {
            $id = (string) Str::uuid7();
            $asset = $project->assets()->create([
                'id' => $id,
                'kind' => ProjectAssetKind::fromFilename($filename),
                'name' => $name,
                'original_name' => basename($filename),
                'directory' => $project->storagePrefix().'/assets/'.$id,
                'entry' => '',
                'bytes' => $bytes,
                'files' => 0,
                'status' => ProjectAssetStatus::Uploading,
            ]);

            $asset->update(['upload_id' => $this->uploads->start($asset->sourceKey())]);

            return [
                'asset' => $asset,
                'partBytes' => $this->uploads->partBytes(),
                'parts' => max(1, (int) ceil($bytes / $this->uploads->partBytes())),
            ];
        });
    }
}
