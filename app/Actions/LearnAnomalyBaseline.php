<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AnomalyExclusion;
use App\Models\AnomalyMonitor;
use Carbon\CarbonImmutable;

final readonly class LearnAnomalyBaseline
{
    /**
     * How far back a monitor learns from.
     */
    public const int WINDOW_DAYS = 28;

    public function __construct(private ReadingHistory $history) {}

    /**
     * Learns what's usual for the metric at each local hour, on weekdays and
     * weekends, from the hourly means of its last four weeks, leaving out
     * the twin's excluded periods.
     */
    public function handle(AnomalyMonitor $monitor): AnomalyMonitor
    {
        $now = now()->getTimestampMs();
        $from = $now - self::WINDOW_DAYS * 86_400_000;
        $twin = $monitor->twin;
        $offset = $this->utcOffset($twin->latestVersion()->firstOrFail()->definition);
        $exclusions = $twin->anomalyExclusions()->where('ends_at', '>', CarbonImmutable::createFromTimestampMs($from))->get();

        $points = array_filter(
            $this->history->handle($twin, $monitor->asset, $monitor->metric, $from, $now, self::WINDOW_DAYS * 24)['points'],
            fn (array $point): bool => $exclusions->doesntContain(fn (AnomalyExclusion $exclusion): bool => $exclusion->starts_at->getTimestampMs() < $point['t'] + 3_600_000 && $point['t'] < $exclusion->ends_at->getTimestampMs()),
        );

        $groups = [];
        foreach ($points as $point) {
            $local = CarbonImmutable::createFromTimestampMsUTC($point['t'])->addMinutes((int) round($offset * 60));
            $groups[($local->isWeekend() ? 'we-' : 'wd-').$local->hour][] = $point['mean'];
            $groups['all-'.$local->hour][] = $point['mean'];
        }

        $slots = array_map($this->slot(...), $groups);
        $first = $points === [] ? $now : min(array_column($points, 't'));

        $monitor->forceFill([
            'baseline' => ['days' => round(($now - $first) / 86_400_000, 1), 'slots' => $slots],
            'learned_at' => now(),
        ])->save();

        return $monitor;
    }

    /**
     * The median of a slot's hourly means, and their spread: the median
     * absolute deviation scaled to a standard deviation, kept off zero so a
     * steady metric isn't called unusual for the smallest wobble.
     *
     * @param  list<float>  $values
     * @return array{median: float, spread: float, n: int}
     */
    private function slot(array $values): array
    {
        $median = $this->median($values);
        $deviation = $this->median(array_map(fn (float $value): float => abs($value - $median), $values));

        return [
            'median' => round($median, 4),
            'spread' => round(max(1.4826 * $deviation, 0.01 * abs($median), 0.01), 4),
            'n' => count($values),
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param  array<mixed>  $definition
     */
    private function utcOffset(array $definition): float
    {
        return is_numeric($definition['utcOffsetHours'] ?? null) ? (float) $definition['utcOffsetHours'] : 0.0;
    }
}
