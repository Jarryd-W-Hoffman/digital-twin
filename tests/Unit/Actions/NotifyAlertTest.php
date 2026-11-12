<?php

declare(strict_types=1);

use App\Actions\NotifyAlert;
use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use App\Enums\AlertSeverity;
use App\Jobs\DeliverAlertNotification;
use App\Models\Alert;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Queue;

it('queues a delivery to each channel that wants the alert, and notes the quiet ones', function (): void {
    Queue::fake();
    $this->travelTo(now()->setTimezone('UTC')->setTime(23, 0));
    $alert = Alert::factory()->create(['severity' => AlertSeverity::Warning]);
    $project = $alert->twin->project;
    $wants = NotificationChannel::factory()->for($project)->create(['name' => 'A ops']);
    NotificationChannel::factory()->for($project)->create(['name' => 'B critical only', 'min_severity' => AlertSeverity::Critical]);
    $quiet = NotificationChannel::factory()->for($project)->quiet()->create(['name' => 'C night']);
    NotificationChannel::factory()->create();

    resolve(NotifyAlert::class)->handle($alert, AlertEvent::Raised);

    $notifications = $alert->notifications()->orderBy('channel_name')->get();
    expect($notifications)->toHaveCount(2)
        ->and($notifications[0]->notification_channel_id)->toBe($wants->id)
        ->and($notifications[0]->status)->toBe(AlertNotificationStatus::Queued)
        ->and($notifications[1]->notification_channel_id)->toBe($quiet->id)
        ->and($notifications[1]->status)->toBe(AlertNotificationStatus::Quiet);
    Queue::assertPushed(DeliverAlertNotification::class, 1);
    Queue::assertPushed(fn (DeliverAlertNotification $job): bool => $job->notification->is($notifications[0]));
});
