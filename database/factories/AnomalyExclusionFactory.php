<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnomalyExclusion;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnomalyExclusion>
 */
final class AnomalyExclusionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(2),
            'note' => 'Shutdown',
        ];
    }
}
