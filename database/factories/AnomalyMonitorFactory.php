<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Enums\AnomalySensitivity;
use App\Models\AnomalyMonitor;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnomalyMonitor>
 */
final class AnomalyMonitorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'asset' => 'store',
            'metric' => 'tempC',
            'sensitivity' => AnomalySensitivity::Medium,
            'severity' => AlertSeverity::Warning,
            'bands' => true,
            'flatline_hours' => null,
        ];
    }

    /**
     * Learned: -19 ±0.5 at every hour, from three weeks of history.
     */
    public function learned(float $median = -19.0, float $spread = 0.5): self
    {
        $slots = [];
        foreach (['wd', 'we', 'all'] as $days) {
            for ($hour = 0; $hour < 24; $hour++) {
                $slots[$days.'-'.$hour] = ['median' => $median, 'spread' => $spread, 'n' => 10];
            }
        }

        return $this->state(['baseline' => ['days' => 21.0, 'slots' => $slots], 'learned_at' => now()]);
    }
}
