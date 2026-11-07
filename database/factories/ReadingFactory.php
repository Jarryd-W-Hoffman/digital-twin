<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Reading;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reading>
 */
final class ReadingFactory extends Factory
{
    /**
     * A cold store temperature from the last day, on a sample twin.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'asset' => 'store',
            'metric' => 'tempC',
            'value' => fake()->randomFloat(1, -22, -16),
            'recorded_at' => fake()->dateTimeBetween('-1 day'),
        ];
    }
}
