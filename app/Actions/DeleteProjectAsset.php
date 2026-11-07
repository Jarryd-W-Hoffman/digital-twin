<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteProjectAsset
{
    public function __construct(private RemoveAssetFromTwin $layers) {}

    /**
     * Takes the upload out of every twin in the project that still shows it,
     * then deletes it, its files and any original still waiting to be
     * processed. The viewer skips layers in older
     * versions that no longer load.
     */
    public function handle(ProjectAsset $asset, ?User $user): void
    {
        DB::transaction(function () use ($asset, $user): void {
            foreach ($asset->project->twins()->get() as $twin) {
                $this->layers->handle($twin, $user, $asset);
            }

            $asset->delete();
        });

        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $disk->deleteDirectory($asset->directory);
        $disk->deleteDirectory(dirname($asset->sourceKey()));
    }
}
