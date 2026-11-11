<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use App\Jobs\DeliverAlertNotification;
use App\Models\Alert;
use App\Models\NotificationChannel;

/**
 * Tells the project's channels that one of its twins' alerts was raised or
 * cleared. Each channel that wants it gets a record and a queued delivery;
 * one in its quiet hours gets a record saying so, and nothing is sent.
 */
final readonly class NotifyAlert
{
    public function handle(Alert $alert, AlertEvent $event): void
    {
        $now = now();
        $channels = $alert->twin->project->notificationChannels()->orderBy('name')->get();

        foreach ($channels as $channel) {
            /** @var NotificationChannel $channel */
            $status = $channel->decide($alert->severity, $event, $now);
            if ($status === null) {
                continue;
            }

            $notification = $alert->notifications()->create([
                'notification_channel_id' => $channel->id,
                'channel_name' => $channel->name,
                'event' => $event,
                'status' => $status,
                'created_at' => $now,
            ]);

            if ($status === AlertNotificationStatus::Queued) {
                dispatch(new DeliverAlertNotification($notification));
            }
        }
    }
}
