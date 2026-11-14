<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEvent;
use App\Models\Alert;
use App\Models\AnomalyMonitor;
use App\Models\Reading;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class DetectAnomaly
{
    /**
     * Minutes of readings averaged to compare with the band, so one odd
     * reading isn't enough.
     */
    public const int WINDOW_MINUTES = 30;

    /**
     * Share of the threshold a reading must come back within to clear, so an
     * alert doesn't flap at the edge.
     */
    public const float CLEAR_AT = 0.75;

    public function __construct(private NotifyAlert $notify) {}

    /**
     * Compares the metric's last half hour with what's usual at this hour,
     * and its last hours with one another, raising or clearing an alert for
     * each. No readings change nothing: a gateway going quiet is its own
     * matter. Nothing is checked during an excluded period.
     */
    public function handle(AnomalyMonitor $monitor): void
    {
        $twin = $monitor->twin;

        if ($twin->anomalyExclusions()->where('starts_at', '<=', now())->where('ends_at', '>', now())->exists()) {
            return;
        }

        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $offset = is_numeric($definition['utcOffsetHours'] ?? null) ? (float) $definition['utcOffsetHours'] : 0.0;
        $name = $this->assetName($definition, $monitor->asset);

        $this->band($monitor, $name, $offset);
        $this->flatline($monitor, $name);
    }

    /**
     * Clears what a monitor has open, when it's removed or stops checking.
     */
    public function clear(AnomalyMonitor $monitor, bool $band = true, bool $flat = true): void
    {
        foreach (array_keys(array_filter([$monitor->rule() => $band, $monitor->rule(true) => $flat])) as $rule) {
            $open = $this->open($monitor, $rule);

            if ($open instanceof Alert) {
                $this->close($open);
            }
        }
    }

    private function band(AnomalyMonitor $monitor, string $name, float $offset): void
    {
        $expected = $monitor->bands ? $monitor->expectedAt(now(), $offset) : null;
        if ($expected === null) {
            return;
        }

        $recent = $this->recent($monitor, now()->subMinutes(self::WINDOW_MINUTES));
        if ($recent === null) {
            return;
        }

        $deviation = abs($recent['mean'] - $expected['median']) / $expected['spread'];
        $threshold = $monitor->sensitivity->threshold();
        $open = $this->open($monitor, $monitor->rule());

        if (! $open instanceof Alert && $deviation >= $threshold) {
            $this->raise($monitor, $monitor->rule(), $recent['mean'], __(':asset :metric is :value, usually :usual ±:spread at this hour', [
                'asset' => $name,
                'metric' => $monitor->metric,
                'value' => $this->number($recent['mean']),
                'usual' => $this->number($expected['median']),
                'spread' => $this->number($expected['spread']),
            ]));
        } elseif ($open instanceof Alert && $deviation < $threshold * self::CLEAR_AT) {
            $this->close($open);
        }
    }

    private function flatline(AnomalyMonitor $monitor, string $name): void
    {
        $hours = $monitor->flatline_hours;
        if ($hours === null) {
            return;
        }

        $recent = $this->recent($monitor, now()->subHours($hours));
        if ($recent === null) {
            return;
        }

        // The same value from the first hour of the window to the last.
        $low = $recent['min'];
        $flat = $low === $recent['max'] && $recent['first'] <= now()->subHours($hours - 1)->getTimestamp() && $recent['last'] >= now()->subHour()->getTimestamp();
        $open = $this->open($monitor, $monitor->rule(true));

        if (! $open instanceof Alert && $flat) {
            $this->raise($monitor, $monitor->rule(true), $low, __(":asset :metric hasn't changed from :value in :hours hours", [
                'asset' => $name,
                'metric' => $monitor->metric,
                'value' => $this->number($low),
                'hours' => $hours,
            ]));
        } elseif ($open instanceof Alert && ! $flat) {
            $this->close($open);
        }
    }

    /**
     * The metric's readings since a moment: their mean, lowest and highest,
     * and when the first and last were taken, in seconds.
     *
     * @return array{mean: float, min: float, max: float, first: int, last: int}|null
     */
    private function recent(AnomalyMonitor $monitor, CarbonInterface $since): ?array
    {
        $row = (array) Reading::query()
            ->where('twin_id', $monitor->twin_id)
            ->where('asset', $monitor->asset)
            ->where('metric', $monitor->metric)
            ->where('recorded_at', '>=', $since)
            ->selectRaw('avg(value) as mean, min(value) as low, max(value) as high, min(recorded_at) as first, max(recorded_at) as last')
            ->toBase()
            ->first();

        if (! is_numeric($row['mean'] ?? null) || ! is_numeric($row['low'] ?? null) || ! is_numeric($row['high'] ?? null) || ! is_string($row['first'] ?? null) || ! is_string($row['last'] ?? null)) {
            return null;
        }

        return [
            'mean' => (float) $row['mean'],
            'min' => (float) $row['low'],
            'max' => (float) $row['high'],
            'first' => CarbonImmutable::parse($row['first'])->getTimestamp(),
            'last' => CarbonImmutable::parse($row['last'])->getTimestamp(),
        ];
    }

    private function open(AnomalyMonitor $monitor, string $rule): ?Alert
    {
        return $monitor->twin->openAlerts()->where('rule', $rule)->where('asset', $monitor->asset)->first();
    }

    private function raise(AnomalyMonitor $monitor, string $rule, float $value, string $message): void
    {
        $alert = DB::transaction(fn (): Alert => $monitor->twin->alerts()->create([
            'rule' => $rule,
            'asset' => $monitor->asset,
            'severity' => $monitor->severity,
            'message' => $message,
            'value' => $value,
            'raised_at' => now(),
        ]));

        $this->notify->handle($alert, AlertEvent::Raised);
    }

    private function close(Alert $alert): void
    {
        $alert->update(['cleared_at' => now()]);
        $this->notify->handle($alert, AlertEvent::Cleared);
    }

    /**
     * @param  array<mixed>  $definition
     */
    private function assetName(array $definition, string $asset): string
    {
        foreach (is_array($definition['assets'] ?? null) ? $definition['assets'] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $asset && is_string($candidate['name'] ?? null)) {
                return $candidate['name'];
            }
        }

        return $asset;
    }

    private function number(float $value): string
    {
        return (string) round($value, abs($value) >= 100 ? 0 : 1);
    }
}
