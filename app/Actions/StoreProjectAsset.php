<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class StoreProjectAsset
{
    public function __construct(private ProcessProjectAssetFile $process) {}

    /**
     * Checks and stores a GLB model or zipped 3D Tiles tileset uploaded
     * through the app, in the project's library. Nothing is shown in a twin
     * until it is added to one.
     */
    public function handle(Project $project, UploadedFile $file, string $name): ProjectAsset
    {
        $id = (string) Str::uuid7();

        return DB::transaction(function () use ($project, $file, $name, $id): ProjectAsset {
            $asset = $project->assets()->create([
                'id' => $id,
                'kind' => ProjectAssetKind::fromFilename($file->getClientOriginalName()),
                'name' => $name,
                'original_name' => $file->getClientOriginalName(),
                'directory' => $project->storagePrefix().'/assets/'.$id,
                'entry' => '',
                'bytes' => (int) $file->getSize(),
                'files' => 0,
                'status' => ProjectAssetStatus::Processing,
            ]);

            try {
                return $this->process->handle($asset, $file->getRealPath());
            } catch (Throwable $throwable) {
                $asset->delete();

                throw $throwable;
            }
        });
    }
}
