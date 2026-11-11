<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened to an alert that people may be told about.
 */
enum AlertEvent: string
{
    case Raised = 'raised';
    case Cleared = 'cleared';
}
