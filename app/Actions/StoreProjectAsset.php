<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetKind;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class StoreProjectAsset
{
    public function __construct(private ExtractTilesetArchive $tilesets) {}

    /**
     * Checks and stores an uploaded GLB model or zipped 3D Tiles tileset in the
     * project's library. Nothing is shown in a twin until it is added to one.
     */
    public function handle(Project $project, UploadedFile $file, string $name): ProjectAsset
    {
        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $id = (string) Str::uuid7();
        $directory = $project->storagePrefix().'/assets/'.$id;
        $kind = mb_strtolower($file->getClientOriginalExtension()) === 'zip' ? ProjectAssetKind::Tileset : ProjectAssetKind::Model;

        try {
            $stored = $kind === ProjectAssetKind::Tileset
                ? $this->tilesets->handle($file->getRealPath(), $disk, $directory)
                : $this->storeModel($file, $disk, $directory);

            return $project->assets()->create([
                'id' => $id,
                'kind' => $kind,
                'name' => $name,
                'original_name' => $file->getClientOriginalName(),
                'directory' => $directory,
                ...$stored,
            ]);
        } catch (Throwable $throwable) {
            $disk->deleteDirectory($directory);

            throw $throwable;
        }
    }

    /**
     * @return array{entry: string, bytes: int, files: int}
     */
    private function storeModel(UploadedFile $file, Filesystem $disk, string $directory): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $header = $handle === false ? false : fread($handle, 12);

        if ($handle !== false) {
            fclose($handle);
        }

        /** @var array{magic: string, version: int, length: int}|false $glb */
        $glb = is_string($header) && mb_strlen($header, '8bit') === 12 ? unpack('a4magic/Vversion/Vlength', $header) : false;

        throw_if(
            $glb === false || $glb['magic'] !== 'glTF' || $glb['version'] !== 2 || $glb['length'] !== $file->getSize(),
            ValidationException::withMessages(['file' => __('That is not a glTF 2.0 binary (.glb) file.')]),
        );

        $disk->putFileAs($directory, $file, 'model.glb');

        return ['entry' => 'model.glb', 'bytes' => $file->getSize(), 'files' => 1];
    }
}
