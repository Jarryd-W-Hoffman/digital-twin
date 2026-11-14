<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How often a channel gets the project's report: each morning at 7:00, or
 * on Monday mornings, in the channel's time zone.
 */
enum ReportFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    public const int HOUR = 7;

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
        };
    }

    /**
     * The latest time a report fell due, at or before $at, in UTC.
     */
    public function dueAt(CarbonInterface $at, string $timezone): CarbonImmutable
    {
        $local = CarbonImmutable::instance($at)->setTimezone($timezone);
        $due = $local->setTime(self::HOUR, 0);

        if ($this === self::Weekly) {
            $due = $due->startOfWeek(CarbonInterface::MONDAY)->setTime(self::HOUR, 0);
        }

        if ($due->greaterThan($local)) {
            $due = $this === self::Weekly ? $due->subWeek() : $due->subDay();
        }

        return $due->utc();
    }

    /**
     * The period a report due at $due covers: the day or week before it.
     */
    public function since(CarbonImmutable $due): CarbonImmutable
    {
        return $this === self::Weekly ? $due->subWeek() : $due->subDay();
    }
}
