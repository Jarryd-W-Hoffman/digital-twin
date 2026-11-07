<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssetType;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetType>
 */
final class AssetTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->bothify('meter-###');

        return [
            'organisation_id' => Organisation::factory(),
            'slug' => $slug,
            'name' => 'Flow meter',
            'definition' => [
                'id' => $slug,
                'name' => 'Flow meter',
                'primary' => 'flowLps',
                'metrics' => [['key' => 'flowLps', 'label' => 'Flow', 'unit' => 'L/s', 'default' => 4]],
                'visual' => ['color' => '#3a7ca5', 'shape' => 'cylinder', 'height' => 2],
            ],
        ];
    }
}
