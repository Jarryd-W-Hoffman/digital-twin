<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AnomalySensitivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AnomalyMonitorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a twin's metrics watched for readings unusual for the time of day
 * (against a band learned from its history), or stuck at one value.
 *
 * The baseline holds, per local hour, on weekdays and weekends and both
 * together, the median of the hourly means and their spread (scaled median
 * absolute deviation), with how many hours each is drawn from.
 *
 * @property-read string $id
 * @property-read string $twin_id
 * @property-read string $asset
 * @property-read string $metric
 * @property-read AnomalySensitivity $sensitivity
 * @property-read AlertSeverity $severity
 * @property-read bool $bands
 * @property-read int|null $flatline_hours
 * @property-read array{days: float, slots: array<string, array{median: float, spread: float, n: int}>}|null $baseline
 * @property-read CarbonInterface|null $learned_at
 * @property-read string|null $created_by
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Twin $twin
 */
final class AnomalyMonitor extends Model
{
    /** @use HasFactory<AnomalyMonitorFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * What its alerts' rules start with, which the gateway's never do.
     */
    public const string RULE_PREFIX = 'anomaly:';

    /**
     * Days of history before the band is trusted.
     */
    public const int LEARNING_DAYS = 14;

    /**
     * Hours a slot needs to stand on its own; fewer, and weekdays and
     * weekends are taken together for that hour.
     */
    public const int MIN_SAMPLES = 4;

    /**
     * The alert rule its band raises; flatlines add ":flat".
     */
    public function rule(bool $flat = false): string
    {
        return self::RULE_PREFIX.$this->metric.($flat ? ':flat' : '');
    }

    public function ready(): bool
    {
        return $this->baseline !== null && $this->baseline['days'] >= self::LEARNING_DAYS;
    }

    /**
     * The usual value and spread at an instant, in the twin's local time.
     *
     * @return array{median: float, spread: float}|null
     */
    public function expectedAt(CarbonInterface $at, float $utcOffsetHours): ?array
    {
        if (! $this->ready() || $this->baseline === null) {
            return null;
        }

        $local = CarbonImmutable::createFromTimestampUTC($at->getTimestamp())->addMinutes((int) round($utcOffsetHours * 60));
        $hour = $local->hour;
        $slots = $this->baseline['slots'];
        $own = $slots[($local->isWeekend() ? 'we-' : 'wd-').$hour] ?? null;
        $slot = $own !== null && $own['n'] >= self::MIN_SAMPLES ? $own : ($slots['all-'.$hour] ?? null);

        // Stored as JSON, a whole number comes back an integer.
        return $slot !== null && $slot['n'] >= self::MIN_SAMPLES ? ['median' => (float) $slot['median'], 'spread' => (float) $slot['spread']] : null;
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'twin_id' => 'string',
            'asset' => 'string',
            'metric' => 'string',
            'sensitivity' => AnomalySensitivity::class,
            'severity' => AlertSeverity::class,
            'bands' => 'boolean',
            'flatline_hours' => 'integer',
            'baseline' => 'array',
            'learned_at' => 'datetime',
            'created_by' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Twin, $this>
     */
    public function twin(): BelongsTo
    {
        return $this->belongsTo(Twin::class);
    }
}
