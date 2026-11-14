<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReadingImportStatus;
use App\Models\ReadingImport;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingImport>
 */
final class ReadingImportFactory extends Factory
{
    /**
     * A wide file of the depot store's temperature, waiting to be imported.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twin_id' => Twin::factory(),
            'file_name' => 'store.csv',
            'path' => null,
            'settings' => ['layout' => 'wide', 'delimiter' => ',', 'time' => 'Time', 'format' => 'iso'],
            'series' => [['name' => 'Store temperature', 'asset' => 'store', 'metric' => 'tempC']],
            'status' => ReadingImportStatus::Importing,
        ];
    }

    /**
     * Imported: 48 readings over the day before yesterday.
     */
    public function imported(): self
    {
        return $this->state(fn (): array => [
            'status' => ReadingImportStatus::Done,
            'summary' => ['rows' => 48, 'stored' => 48, 'skipped' => 0, 'problems' => [], 'unmapped' => []],
            'first_at' => now()->subDays(2)->startOfDay(),
            'last_at' => now()->subDays(2)->endOfDay()->startOfSecond(),
        ]);
    }
}
