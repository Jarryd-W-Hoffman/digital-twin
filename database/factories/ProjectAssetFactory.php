<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectAssetKind;
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
        ];
    }
}
