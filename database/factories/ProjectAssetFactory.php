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

    public function processing(): self
    {
        return $this->state(['status' => ProjectAssetStatus::Processing, 'entry' => '', 'files' => 0]);
    }
}
