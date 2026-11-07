<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetKind;
use App\Models\ProjectAsset;
use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;
use InvalidArgumentException;

final readonly class AddAssetToTwin
{
    public function __construct(private SaveTwinVersion $versions) {}

    /**
     * Shows a library upload in the twin as a new version: models at the
     * origin, ready to be placed, and tilesets where their own georeference
     * puts them. A point cloud is a tileset; one without a coordinate system
     * is in local metres, so it starts placed at the origin too. Terrain
     * becomes the twin's ground, replacing any it had. Null when the latest
     * version already shows it.
     */
    public function handle(Twin $twin, ?User $user, ProjectAsset $asset): ?TwinVersion
    {
        throw_if($asset->project_id !== $twin->project_id, InvalidArgumentException::class, 'The upload belongs to another project.');
        throw_unless($asset->isReady(), InvalidArgumentException::class, 'The upload is not ready yet.');

        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $layer = ['name' => $asset->name, 'url' => $asset->url(), 'assetId' => $asset->id];

        if ($asset->kind === ProjectAssetKind::Terrain) {
            $current = $definition['terrain'] ?? null;

            if (is_array($current) && ($current['assetId'] ?? null) === $asset->id) {
                return null;
            }

            $definition['terrain'] = $layer;

            return $this->versions->handle($twin, $user, $definition, __('Set the terrain to :name', ['name' => $asset->name]));
        }

        $field = $asset->kind->isTileset() ? 'tilesets' : 'models';
        $layers = is_array($definition[$field] ?? null) ? array_values($definition[$field]) : [];

        if (array_any($layers, fn (mixed $layer): bool => is_array($layer) && ($layer['assetId'] ?? null) === $asset->id)) {
            return null;
        }

        $layer = ['id' => 'upload-'.mb_substr($asset->id, -8), ...$layer];

        if ($asset->kind === ProjectAssetKind::Model || ($asset->kind === ProjectAssetKind::PointCloud && $asset->crs === null)) {
            $layer['placement'] = ['east' => 0, 'north' => 0, 'up' => 0, 'headingDeg' => 0, 'scale' => 1];
        }

        $definition[$field] = [...$layers, $layer];

        return $this->versions->handle($twin, $user, $definition, __('Added :name', ['name' => $asset->name]));
    }
}
