<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUpload;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class StoreProjectAsset
{
    public function __construct(private ProcessProjectAssetFile $process, private RecordAuditEvent $audit) {}

    /**
     * Adds a file uploaded through the app to the project's library. A GLB
     * model or zipped 3D Tiles tileset is checked and stored now; a point
     * cloud or DEM is kept as uploaded and queued for conversion. Nothing is shown
     * in a twin until it is added to one.
     *
     * @param  array{epsg?: int, spacing?: float, as?: string, y_up?: bool, captured_on?: string}  $options  Conversion options.
     */
    public function handle(Project $project, UploadedFile $file, string $name, array $options = []): ProjectAsset
    {
        $id = (string) Str::uuid7();
        $kind = ProjectAssetKind::fromFilename($file->getClientOriginalName(), $options['as'] ?? null);

        return DB::transaction(function () use ($project, $file, $name, $id, $kind, $options): ProjectAsset {
            $asset = $project->assets()->create([
                'id' => $id,
                'kind' => $kind,
                'name' => $name,
                'original_name' => basename($file->getClientOriginalName()),
                'directory' => $project->storagePrefix().'/assets/'.$id,
                'entry' => '',
                'bytes' => (int) $file->getSize(),
                'files' => 0,
                'status' => ProjectAssetStatus::Processing,
                'options' => $kind->isConverted() ? Arr::except($options, ['as', 'captured_on']) : null,
                'captured_on' => $options['captured_on'] ?? null,
            ]);
            $this->audit->handle(AuditAction::UploadAdded, $project, $name, ['kind' => $kind->value, 'file' => $asset->original_name]);

            if ($kind->isConverted()) {
                $key = $asset->sourceKey();
                Storage::disk(config()->string('meridian.assets_disk'))->putFileAs(dirname($key), $file, basename($key));
                dispatch(new ConvertUpload($asset))->afterCommit();

                return $asset;
            }

            try {
                return $this->process->handle($asset, $file->getRealPath());
            } catch (Throwable $throwable) {
                $asset->delete();

                throw $throwable;
            }
        });
    }
}
