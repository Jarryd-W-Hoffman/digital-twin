<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Issue;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
final class IssueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'number' => fn (array $attributes): int => is_numeric($last = Issue::query()->where('twin_id', $attributes['twin_id'])->max('number')) ? (int) $last + 1 : 1,
            'title' => 'Cracked slab by the loading bay',
            'lon' => 174.7633,
            'lat' => -36.8485,
            'height' => 12.5,
            'view' => ['lon' => 174.7629, 'lat' => -36.8489, 'height' => 60.0, 'heading' => 30.0, 'pitch' => -35.0, 'roll' => 0.0],
            'author_id' => User::factory(),
        ];
    }

    /**
     * Dealt with, by someone.
     */
    public function closed(): self
    {
        return $this->state(['closed_at' => now(), 'closed_by' => User::factory()]);
    }
}
