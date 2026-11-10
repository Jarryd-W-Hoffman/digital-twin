<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OpcUaWrite;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpcUaWrite>
 */
final class OpcUaWriteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'server' => 'Plant PLC',
            'node' => 'ns=2;s=Jockey.Start',
            'asset' => 'jockey',
            'metric' => 'startKpa',
            'requested' => 600,
            'setpoint' => 600,
            'value' => 6,
            'succeeded' => true,
            'written_at' => now(),
        ];
    }

    /**
     * Refused by the server.
     */
    public function failed(string $error = 'BadNotWritable'): self
    {
        return $this->state(['succeeded' => false, 'error' => $error]);
    }
}
