<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Reading;
use App\Models\Twin;
use Carbon\CarbonImmutable;

final readonly class RecordReadings
{
    /**
     * A time as readings store it, so they compare as strings on any database.
     */
    public static function format(int $ms): string
    {
        return CarbonImmutable::createFromTimestampMs($ms, 'UTC')->format('Y-m-d H:i:s.vP');
    }

    /**
     * Stores a batch of a twin's readings for its history.
     *
     * @param  list<array{asset: string, metric: string, value: float, at: int}>  $readings
     */
    public function handle(Twin $twin, array $readings): int
    {
        $rows = array_map(fn (array $r): array => [
            'twin_id' => $twin->id,
            'asset' => $r['asset'],
            'metric' => $r['metric'],
            'value' => $r['value'],
            'recorded_at' => self::format($r['at']),
        ], $readings);

        foreach (array_chunk($rows, 500) as $chunk) {
            Reading::query()->insert($chunk);
        }

        return count($rows);
    }
}
