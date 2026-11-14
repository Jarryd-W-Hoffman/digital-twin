<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ModbusDevice;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModbusDevice>
 */
final class ModbusDeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Main meter',
            'host' => 'meter.local',
            'port' => 502,
            'unit_id' => 1,
        ];
    }
}
