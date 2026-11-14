<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far from usual a reading has to be, in spreads, to be called unusual.
 */
enum AnomalySensitivity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Spreads from the usual value at which a reading is unusual.
     */
    public function threshold(): float
    {
        return match ($this) {
            self::Low => 4.0,
            self::Medium => 3.0,
            self::High => 2.0,
        };
    }
}
