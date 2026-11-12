<?php

declare(strict_types=1);

use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use App\Jobs\DeliverAlertNotification;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Mail;

it('delivers, and records it on the notification and the channel', function (): void {
    $this->freezeSecond();
    Mail::fake();
    $alert = Alert::factory()->create();
    $channel = NotificationChannel::factory()->for($alert->twin->project)->create(['last_error' => 'old']);
    $notification = AlertNotification::factory()->for($alert)->create(['notification_channel_id' => $channel->id, 'event' => AlertEvent::Cleared]);

    dispatch_sync(new DeliverAlertNotification($notification));

    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->alert->event === AlertEvent::Cleared);
    expect($notification->refresh()->status)->toBe(AlertNotificationStatus::Sent)
        ->and($notification->attempted_at?->equalTo(now()))->toBeTrue()
        ->and($channel->refresh()->last_sent_at?->equalTo(now()))->toBeTrue()
        ->and($channel->last_error)->toBeNull();
});

it('records why it gave up', function (): void {
    $alert = Alert::factory()->create();
    $channel = NotificationChannel::factory()->for($alert->twin->project)->create();
    $notification = AlertNotification::factory()->for($alert)->create(['notification_channel_id' => $channel->id]);

    new DeliverAlertNotification($notification)->failed(new RuntimeException(str_repeat('x', 600)));

    expect($notification->refresh()->status)->toBe(AlertNotificationStatus::Failed)
        ->and($notification->error)->toHaveLength(500)
        ->and($channel->refresh()->last_error)->toHaveLength(500);

    new DeliverAlertNotification($notification)->failed(null);
    expect($notification->refresh()->error)->toBe('Delivery failed.');
});

it('gives up on a channel deleted since', function (): void {
    $notification = AlertNotification::factory()->create();
    $notification->channel?->delete();

    dispatch_sync(new DeliverAlertNotification($notification->refresh()));

    expect($notification->refresh()->status)->toBe(AlertNotificationStatus::Failed)
        ->and($notification->error)->toBe('The channel was deleted.')
        ->and($notification->channel_name)->toBe('Ops email');
});

it('goes on the queue connection set for notifications', function (): void {
    $notification = AlertNotification::factory()->create();

    expect(new DeliverAlertNotification($notification)->connection)->toBeNull();

    config(['meridian.notifications.connection' => 'sync']);
    expect(new DeliverAlertNotification($notification)->connection)->toBe('sync');
});
