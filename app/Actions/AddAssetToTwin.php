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
    private const array ORIGIN = ['east' => 0, 'north' => 0, 'up' => 0, 'headingDeg' => 0, 'scale' => 1];

    /**
     * A georeferenced model further than this from the twin's origin is
     * probably meant for another site, so it starts at the origin instead.
     */
    private const float NEARBY_M = 50_000;

    private const float EARTH_RADIUS_M = 6_378_137;

    public function __construct(private SaveTwinVersion $versions) {}

    /**
     * Shows a library upload in the twin as a new version: models at the
     * origin, ready to be placed, and tilesets where their own georeference
     * puts them. A converted IFC model starts where the file says it is, if
     * that's near the twin. A point cloud or mesh is a tileset; one without a
     * coordinate system is in local metres, so it starts placed at the origin
     * too. Terrain
     * becomes the twin's ground, replacing any it had. Map layers and imagery
     * are already in longitude and latitude, so they go where they are. Null
     * when the latest version already shows it.
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

        $field = match (true) {
            $asset->kind->isTileset() => 'tilesets',
            $asset->kind === ProjectAssetKind::Vector => 'vectors',
            $asset->kind === ProjectAssetKind::Imagery => 'imagery',
            default => 'models',
        };
        $layers = is_array($definition[$field] ?? null) ? array_values($definition[$field]) : [];

        if (array_any($layers, fn (mixed $layer): bool => is_array($layer) && ($layer['assetId'] ?? null) === $asset->id)) {
            return null;
        }

        $layer = ['id' => 'upload-'.mb_substr($asset->id, -8), ...$layer];

        if ($asset->kind === ProjectAssetKind::Bim) {
            $layer['placement'] = $this->placeModel($definition, $asset->details['georeference'] ?? null);
        } elseif ($asset->kind === ProjectAssetKind::Model || (in_array($asset->kind, [ProjectAssetKind::PointCloud, ProjectAssetKind::Mesh], true) && $asset->crs === null)) {
            $layer['placement'] = self::ORIGIN;
        }

        $definition[$field] = [...$layers, $layer];

        return $this->versions->handle($twin, $user, $definition, __('Added :name', ['name' => $asset->name]));
    }

    /**
     * Where a georeferenced model's origin is in the twin, in metres east and
     * north of the twin's origin (the viewer's local projection), facing the
     * way the file says.
     *
     * @param  array<string, mixed>  $definition
     * @param  array{lon: float, lat: float, headingDeg: float, source: string}|null  $georeference
     * @return array{east: float|int, north: float|int, up: int, headingDeg: float|int, scale: int}
     */
    private function placeModel(array $definition, ?array $georeference): array
    {
        $origin = is_array($definition['origin'] ?? null) ? $definition['origin'] : [];
        $lon = $origin['lon'] ?? null;
        $lat = $origin['lat'] ?? null;

        if ($georeference === null || ! is_numeric($lon) || ! is_numeric($lat)) {
            return self::ORIGIN;
        }

        $north = deg2rad($georeference['lat'] - (float) $lat) * self::EARTH_RADIUS_M;
        $east = deg2rad($georeference['lon'] - (float) $lon) * self::EARTH_RADIUS_M * cos(deg2rad((float) $lat));

        if (hypot($east, $north) > self::NEARBY_M) {
            return self::ORIGIN;
        }

        return [
            'east' => round($east, 2),
            'north' => round($north, 2),
            'up' => 0,
            'headingDeg' => round($georeference['headingDeg'], 2),
            'scale' => 1,
        ];
    }
}
