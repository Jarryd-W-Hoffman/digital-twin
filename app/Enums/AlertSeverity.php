<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How serious a twin's rule says an alert is, as its definition names it.
 */
enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
