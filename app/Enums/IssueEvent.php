<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened to an issue that people may be told about.
 */
enum IssueEvent: string
{
    case Raised = 'raised';
    case Assigned = 'assigned';
    case Closed = 'closed';
}
