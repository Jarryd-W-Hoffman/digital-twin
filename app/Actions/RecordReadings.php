<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Reading;
use App\Models\Twin;
use Carbon\CarbonImmutable;

final readonly class RecordReadings
{
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
            'recorded_at' => CarbonImmutable::createFromTimestampMs($r['at'], 'UTC')->format('Y-m-d H:i:s.vP'),
        ], $readings);

        foreach (array_chunk($rows, 500) as $chunk) {
            Reading::query()->insert($chunk);
        }

        return count($rows);
    }
}
