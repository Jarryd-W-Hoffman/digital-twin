<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TwinAssetKind;
use App\Models\Twin;
use App\Models\TwinAsset;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class StoreTwinAsset
{
    public function __construct(
        private ExtractTilesetArchive $tilesets,
        private SaveTwinVersion $versions,
    ) {}

    /**
     * Stores an uploaded GLB model or zipped 3D Tiles tileset and adds it to the
     * twin as a new version: models at the origin, ready to be placed, and
     * tilesets where their own georeference puts them.
     */
    public function handle(Twin $twin, User $user, UploadedFile $file, string $name): TwinAsset
    {
        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $id = (string) Str::uuid7();
        $directory = $twin->storagePrefix().'/assets/'.$id;
        $kind = mb_strtolower($file->getClientOriginalExtension()) === 'zip' ? TwinAssetKind::Tileset : TwinAssetKind::Model;

        try {
            $stored = $kind === TwinAssetKind::Tileset
                ? $this->tilesets->handle($file->getRealPath(), $disk, $directory)
                : $this->storeModel($file, $disk, $directory);

            return DB::transaction(function () use ($twin, $user, $id, $kind, $name, $file, $stored): TwinAsset {
                $asset = $twin->assets()->create([
                    'id' => $id,
                    'kind' => $kind,
                    'name' => $name,
                    'original_name' => $file->getClientOriginalName(),
                    ...$stored,
                ]);

                $this->versions->handle($twin, $user, $this->withLayer($twin, $asset), __('Added :name', ['name' => $name]));

                return $asset;
            });
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

    /**
     * @return array<string, mixed>
     */
    private function withLayer(Twin $twin, TwinAsset $asset): array
    {
        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $field = $asset->kind === TwinAssetKind::Tileset ? 'tilesets' : 'models';
        $layer = ['id' => 'upload-'.mb_substr($asset->id, -8), 'name' => $asset->name, 'url' => $asset->url(), 'assetId' => $asset->id];

        if ($asset->kind === TwinAssetKind::Model) {
            $layer['placement'] = ['east' => 0, 'north' => 0, 'up' => 0, 'headingDeg' => 0, 'scale' => 1];
        }

        $layers = is_array($definition[$field] ?? null) ? array_values($definition[$field]) : [];
        $definition[$field] = [...$layers, $layer];

        return $definition;
    }
}
