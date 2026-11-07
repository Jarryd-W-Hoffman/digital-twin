<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
final class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->bothify('sensor-###'),
            'password_hash' => hash('sha256', 'mdv_secret'),
        ];
    }
}
