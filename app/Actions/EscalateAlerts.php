<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEvent;
use App\Models\Alert;
use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tells each escalation channel of its project's alerts that are still open
 * and unacknowledged after its delay, once each. Run every minute by the
 * scheduler; acknowledging an alert, or its clearing, stops it there.
 */
final readonly class EscalateAlerts
{
    public function __construct(private NotifyAlert $notify) {}

    public function handle(): void
    {
        $now = now();

        foreach (NotificationChannel::query()->whereNotNull('escalate_after_minutes')->get() as $channel) {
            $alerts = Alert::query()
                ->whereNull('cleared_at')
                ->whereNull('acknowledged_at')
                ->where('raised_at', '<=', $now->copy()->subMinutes((int) $channel->escalate_after_minutes))
                ->whereHas('twin', fn (Builder $twin): Builder => $twin->where('project_id', $channel->project_id))
                ->whereDoesntHave('notifications', fn (Builder $notification): Builder => $notification
                    ->where('notification_channel_id', $channel->id)
                    ->where('event', AlertEvent::Escalated))
                ->oldest('raised_at')
                ->limit(100)
                ->get();

            foreach ($alerts as $alert) {
                $this->notify->handle($alert, AlertEvent::Escalated, collect([$channel]));
            }
        }
    }
}
