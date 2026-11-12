<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one notification of an alert to one channel got to: waiting for the
 * queue, delivered, given up on, or held back by the channel's quiet hours.
 */
enum AlertNotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Quiet = 'quiet';
}
