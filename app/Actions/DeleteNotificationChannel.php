<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\NotificationChannel;

/**
 * Deletes a channel. What it was sent stays in the alert log by its name.
 */
final readonly class DeleteNotificationChannel
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(NotificationChannel $channel): void
    {
        $channel->delete();

        $this->audit->handle(AuditAction::ChannelDeleted, $channel->project, $channel->name);
    }
}
