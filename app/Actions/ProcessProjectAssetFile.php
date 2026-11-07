<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Models\ProjectAsset;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class ProcessProjectAssetFile
{
    public function __construct(private ExtractTilesetArchive $tilesets) {}

    /**
     * Checks a local copy of an upload and writes it into the asset's
     * directory: a GLB model as is, a tileset archive unpacked entry by entry.
     * The asset is then ready; on any failure its files are removed and the
     * exception is rethrown.
     */
    public function handle(ProjectAsset $asset, string $path): ProjectAsset
    {
        $disk = Storage::disk(config()->string('meridian.assets_disk'));

        try {
            $stored = $asset->kind === ProjectAssetKind::Tileset
                ? $this->tilesets->handle($path, $disk, $asset->directory)
                : $this->storeModel($path, $disk, $asset->directory);
        } catch (Throwable $throwable) {
            $disk->deleteDirectory($asset->directory);

            throw $throwable;
        }

        $asset->update([...$stored, 'status' => ProjectAssetStatus::Ready, 'error' => null, 'upload_id' => null]);

        return $asset;
    }

    /**
     * @return array{entry: string, bytes: int, files: int}
     */
    private function storeModel(string $path, Filesystem $disk, string $directory): array
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        $header = $handle === false ? false : fread($handle, 12);

        if ($handle !== false) {
            fclose($handle);
        }

        /** @var array{magic: string, version: int, length: int}|false $glb */
        $glb = is_string($header) && mb_strlen($header, '8bit') === 12 ? unpack('a4magic/Vversion/Vlength', $header) : false;

        throw_if(
            $glb === false || $glb['magic'] !== 'glTF' || $glb['version'] !== 2 || $glb['length'] !== $size,
            ValidationException::withMessages(['file' => __('That is not a glTF 2.0 binary (.glb) file.')]),
        );

        $disk->putFileAs($directory, new File($path), 'model.glb');

        return ['entry' => 'model.glb', 'bytes' => $size, 'files' => 1];
    }
}
