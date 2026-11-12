<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a project's alert notifications go: email addresses, a signed JSON
 * webhook, a Slack incoming webhook, or a Microsoft Teams workflow.
 */
enum NotificationChannelKind: string
{
    case Email = 'email';
    case Webhook = 'webhook';
    case Slack = 'slack';
    case Teams = 'teams';
}
