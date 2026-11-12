<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened to an alert that people may be told about: raised, still
 * unacknowledged after a channel's escalation delay, or cleared.
 */
enum AlertEvent: string
{
    case Raised = 'raised';
    case Escalated = 'escalated';
    case Cleared = 'cleared';
}
