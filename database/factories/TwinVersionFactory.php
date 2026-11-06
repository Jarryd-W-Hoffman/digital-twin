<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Twin;
use App\Models\TwinVersion;
use App\Support\SampleTwins;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TwinVersion>
 */
final class TwinVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'number' => 1,
            'definition' => resolve(SampleTwins::class)->definition('harbour-depot'),
            'note' => null,
        ];
    }
}
