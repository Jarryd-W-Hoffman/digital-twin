<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Models\Alert;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
final class AlertFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'rule' => 'warm',
            'asset' => 'store',
            'severity' => AlertSeverity::Critical,
            'message' => 'Seafood store is above its temperature limit',
            'value' => 1.5,
            'raised_at' => now(),
        ];
    }

    /**
     * No longer open.
     */
    public function cleared(): self
    {
        return $this->state(['cleared_at' => now()]);
    }
}
