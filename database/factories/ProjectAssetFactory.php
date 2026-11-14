<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectAsset>
 */
final class ProjectAssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'kind' => ProjectAssetKind::Model,
            'name' => 'Pump skid',
            'original_name' => 'pump.glb',
            'directory' => 'projects/factory/assets/'.fake()->uuid(),
            'entry' => 'model.glb',
            'bytes' => 1024,
            'files' => 1,
            'status' => ProjectAssetStatus::Ready,
        ];
    }

    /**
     * A direct upload whose parts are still arriving.
     */
    public function uploading(string $uploadId = 'up-1'): self
    {
        return $this->state(['status' => ProjectAssetStatus::Uploading, 'upload_id' => $uploadId, 'entry' => '', 'files' => 0]);
    }

    /**
     * A converted point cloud, in the given coordinate system (null: local metres).
     */
    public function pointCloud(?string $crs = 'EPSG:2193'): self
    {
        return $this->state(['kind' => ProjectAssetKind::PointCloud, 'original_name' => 'scan.laz', 'entry' => 'tileset.json', 'files' => 2, 'crs' => $crs, 'options' => []]);
    }

    /**
     * A converted IFC model, georeferenced or not.
     *
     * @param  array{lon: float, lat: float, headingDeg: float, source: string}|null  $georeference
     */
    public function bim(?array $georeference = null): self
    {
        return $this->state([
            'kind' => ProjectAssetKind::Bim,
            'name' => 'Shed',
            'original_name' => 'shed.ifc',
            'files' => 2,
            'details' => ['elements' => 6, 'georeference' => $georeference],
        ]);
    }

    /**
     * Terrain converted from a DEM that kept its heights, so it can be
     * compared with other surveys.
     */
    public function survey(string $name = 'Ground survey'): self
    {
        return $this->state([
            'kind' => ProjectAssetKind::Terrain,
            'name' => $name,
            'original_name' => 'ground.tif',
            'entry' => 'layer.json',
            'files' => 3,
            'crs' => 'EPSG:2193',
            'details' => ['surface' => 'surface.tif'],
        ]);
    }

    public function processing(): self
    {
        return $this->state(['status' => ProjectAssetStatus::Processing, 'entry' => '', 'files' => 0]);
    }
}
