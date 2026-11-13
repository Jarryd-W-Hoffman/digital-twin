<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SavedView;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedView>
 */
final class SavedViewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'author_id' => User::factory(),
            'name' => 'Chiller at night',
            'state' => [
                'camera' => ['lon' => 174.7629, 'lat' => -36.8489, 'height' => 60.0, 'heading' => 30.0, 'pitch' => -35.0, 'roll' => 0.0],
                'colorBy' => 'temperature',
                'basemap' => 'offline',
                'layers' => ['labels' => true, 'flows' => true, 'zones' => true, 'context' => true, 'shadows' => false],
                'section' => null,
                'selected' => null,
            ],
            'shared' => false,
        ];
    }

    /**
     * Shared with everyone who can see the twin.
     */
    public function shared(): self
    {
        return $this->state(['shared' => true]);
    }
}
