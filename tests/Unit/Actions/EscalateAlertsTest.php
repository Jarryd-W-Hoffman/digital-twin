<?php

declare(strict_types=1);

use App\Actions\EscalateAlerts;
use App\Actions\NotifyAlert;
use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use App\Enums\AlertSeverity;
use App\Jobs\DeliverAlertNotification;
use App\Models\Alert;
use App\Models\NotificationChannel;
use App\Models\Twin;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{Twin, NotificationChannel}
 */
function escalationSetUp(int $minutes = 15): array
{
    [, $organisation] = member();
    $twin = twinIn($organisation);

    return [$twin, NotificationChannel::factory()->for($twin->project)->escalation($minutes)->create(['name' => 'Manager'])];
}

it('escalates alerts left unacknowledged past the delay, once', function (): void {
    Queue::fake();
    $this->travelTo(now()->setTimezone('UTC')->setTime(12, 0));
    [$twin, $manager] = escalationSetUp();
    $late = Alert::factory()->for($twin)->create(['raised_at' => now()->subMinutes(16)]);
    Alert::factory()->for($twin)->create(['raised_at' => now()->subMinutes(10)]);
    Alert::factory()->for($twin)->create(['raised_at' => now()->subHour(), 'acknowledged_at' => now()]);
    Alert::factory()->for($twin)->cleared()->create(['raised_at' => now()->subHour()]);
    Alert::factory()->create(['raised_at' => now()->subHour()]);

    resolve(EscalateAlerts::class)->handle();
    resolve(EscalateAlerts::class)->handle();

    $notification = $late->notifications()->sole();
    expect($notification->notification_channel_id)->toBe($manager->id)
        ->and($notification->event)->toBe(AlertEvent::Escalated)
        ->and($notification->status)->toBe(AlertNotificationStatus::Queued);
    Queue::assertPushed(DeliverAlertNotification::class, 1);
});

it("skips alerts below the channel's severity", function (): void {
    Queue::fake();
    [$twin, $manager] = escalationSetUp();
    $manager->update(['min_severity' => AlertSeverity::Critical]);
    Alert::factory()->for($twin)->create(['severity' => AlertSeverity::Warning, 'raised_at' => now()->subHour()]);

    resolve(EscalateAlerts::class)->handle();

    Queue::assertNothingPushed();
});

it('waits out quiet hours, then escalates', function (): void {
    Queue::fake();
    $this->travelTo(now()->setTimezone('UTC')->setTime(23, 0));
    [$twin, $manager] = escalationSetUp();
    $manager->update(['quiet_from' => '22:00', 'quiet_until' => '07:00']);
    $alert = Alert::factory()->for($twin)->create(['severity' => AlertSeverity::Warning, 'raised_at' => now()->subHour()]);

    resolve(EscalateAlerts::class)->handle();
    expect($alert->notifications()->count())->toBe(0);

    $this->travelTo(now()->addHours(8));
    resolve(EscalateAlerts::class)->handle();
    expect($alert->notifications()->sole()->status)->toBe(AlertNotificationStatus::Queued);
});

it('tells an escalation channel of a clear only if it was told of the alert', function (): void {
    Queue::fake();
    [$twin, $manager] = escalationSetUp();
    NotificationChannel::factory()->for($twin->project)->escalation(15)->create(['name' => 'Director']);
    NotificationChannel::factory()->for($twin->project)->create(['name' => 'On-call']);
    $alert = Alert::factory()->for($twin)->create(['raised_at' => now()->subMinutes(20)]);

    resolve(NotifyAlert::class)->handle($alert, AlertEvent::Raised);
    $alert->notifications()->create([
        'notification_channel_id' => $manager->id,
        'channel_name' => 'Manager',
        'event' => AlertEvent::Escalated,
        'status' => AlertNotificationStatus::Sent,
        'created_at' => now(),
    ]);
    resolve(NotifyAlert::class)->handle($alert, AlertEvent::Cleared);

    expect($alert->notifications()->orderBy('channel_name')->orderBy('event')->get()->map(fn ($n): string => $n->channel_name.' '.$n->event->value)->all())
        ->toBe(['Manager cleared', 'Manager escalated', 'On-call cleared', 'On-call raised']);
});

it('runs every minute', function (): void {
    $event = collect(resolve(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'escalate-alerts');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('* * * * *');

    Queue::fake();
    [$twin] = escalationSetUp();
    Alert::factory()->for($twin)->create(['raised_at' => now()->subHour()]);
    $event->run(app());
    Queue::assertPushed(DeliverAlertNotification::class, 1);
});
