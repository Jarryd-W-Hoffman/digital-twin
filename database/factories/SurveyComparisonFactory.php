<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SurveyComparisonStatus;
use App\Models\SurveyComparison;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyComparison>
 */
final class SurveyComparisonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'name' => 'North stockpile',
            'area' => [[174.785, -36.8398], [174.7856, -36.8398], [174.7856, -36.8392], [174.785, -36.8392]],
            'surveys' => [],
            'status' => SurveyComparisonStatus::Measuring,
        ];
    }

    /**
     * Measured: between each survey given and the one before, 120 m³ cut and
     * 450 m³ filled over a 3,000 m² area.
     *
     * @param  list<string>  $surveys
     */
    public function measured(array $surveys): self
    {
        return $this->state(fn (): array => [
            'surveys' => $surveys,
            'status' => SurveyComparisonStatus::Ready,
            'measured_at' => now(),
            'results' => array_map(fn (string $before, string $after): array => [
                'before' => $before, 'after' => $after,
                'cut' => 120.0, 'fill' => 450.0, 'net' => 330.0,
                'areaM2' => 3000.0, 'coveredM2' => 2950.0, 'cutM2' => 400.0, 'fillM2' => 900.0,
                'deepestCut' => 0.8, 'highestFill' => 2.4, 'cell' => 0.5,
                'bounds' => [174.785, -36.8398, 174.7856, -36.8392],
            ], array_slice($surveys, 0, -1), array_slice($surveys, 1)),
        ]);
    }
}
