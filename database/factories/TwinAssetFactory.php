<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TwinAssetKind;
use App\Models\Twin;
use App\Models\TwinAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TwinAsset>
 */
final class TwinAssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'kind' => TwinAssetKind::Model,
            'name' => 'Pump skid',
            'original_name' => 'pump.glb',
            'entry' => 'pump.glb',
            'bytes' => 1024,
            'files' => 1,
        ];
    }
}
