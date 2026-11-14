<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AnomalyMonitor;
use App\Models\Twin;
use Carbon\CarbonImmutable;

final readonly class ExpectedBand
{
    /**
     * The usual range at each of a chart's points, for a metric with a
     * learned band: within it is normal at that hour, outside it unusual.
     * Null when the metric isn't watched or is still learning.
     *
     * @param  list<array{t: int, mean: float, min: float, max: float, count: int}>  $points
     * @return list<array{t: int, low: float, high: float}>|null
     */
    public function handle(Twin $twin, string $asset, string $metric, array $points): ?array
    {
        $monitor = $twin->anomalyMonitors()->where('asset', $asset)->where('metric', $metric)->where('bands', true)->first();
        if (! $monitor instanceof AnomalyMonitor || ! $monitor->ready()) {
            return null;
        }

        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $offset = is_numeric($definition['utcOffsetHours'] ?? null) ? (float) $definition['utcOffsetHours'] : 0.0;
        $threshold = $monitor->sensitivity->threshold();
        $band = [];

        foreach ($points as $point) {
            $expected = $monitor->expectedAt(CarbonImmutable::createFromTimestampMsUTC($point['t']), $offset);
            if ($expected !== null) {
                $band[] = [
                    't' => $point['t'],
                    'low' => round($expected['median'] - $threshold * $expected['spread'], 4),
                    'high' => round($expected['median'] + $threshold * $expected['spread'], 4),
                ];
            }
        }

        return $band;
    }
}
