<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use App\Jobs\DeliverAlertNotification;
use App\Models\Alert;
use App\Models\NotificationChannel;
use Illuminate\Support\Collection;

/**
 * Tells channels that one of a project's alerts was raised, escalated or
 * cleared: the project's own channels unless told which. Each channel that
 * wants it gets a record and a queued delivery; one in its quiet hours gets
 * a record saying so, and nothing is sent. An escalation held by quiet hours
 * isn't recorded, so it's tried again once they end. An escalation channel
 * hears of a clearing only if it was told of the alert.
 */
final readonly class NotifyAlert
{
    /**
     * @param  Collection<int, NotificationChannel>|null  $channels
     */
    public function handle(Alert $alert, AlertEvent $event, ?Collection $channels = null): void
    {
        $now = now();
        $channels ??= $alert->twin->project->notificationChannels()->orderBy('name')->get();
        $escalatedTo = $event === AlertEvent::Cleared
            ? $alert->notifications()
                ->where('event', AlertEvent::Escalated)
                ->whereNot('status', AlertNotificationStatus::Quiet)
                ->pluck('notification_channel_id')
                ->all()
            : [];

        foreach ($channels as $channel) {
            $status = $channel->decide($alert->severity, $event, $now);
            if ($status === null) {
                continue;
            }

            if ($event === AlertEvent::Escalated && $status === AlertNotificationStatus::Quiet) {
                continue;
            }

            if ($event === AlertEvent::Cleared && $channel->escalates() && ! in_array($channel->id, $escalatedTo, true)) {
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
