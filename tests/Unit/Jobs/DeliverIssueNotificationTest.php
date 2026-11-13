<?php

declare(strict_types=1);

use App\Enums\IssueEvent;
use App\Jobs\DeliverIssueNotification;
use App\Mail\IssueMail;
use App\Models\Issue;
use App\Models\NotificationChannel;
use App\Support\IssueMessage;
use Illuminate\Support\Facades\Mail;

it('delivers, and records it on the channel', function (): void {
    $this->freezeSecond();
    Mail::fake();
    $issue = Issue::factory()->create();
    $channel = NotificationChannel::factory()->for($issue->twin->project)->issues()->create(['last_error' => 'old']);

    dispatch_sync(new DeliverIssueNotification($channel, IssueMessage::about($issue, IssueEvent::Closed, null)));

    Mail::assertSent(IssueMail::class, fn (IssueMail $mail): bool => $mail->hasTo('ops@example.com')
        && ! $mail->toAssignee
        && $mail->issue->event === IssueEvent::Closed);
    expect($channel->refresh()->last_sent_at?->equalTo(now()))->toBeTrue()
        ->and($channel->last_error)->toBeNull();
});

it('records why it gave up', function (): void {
    $issue = Issue::factory()->create();
    $channel = NotificationChannel::factory()->for($issue->twin->project)->create();
    $job = new DeliverIssueNotification($channel, IssueMessage::about($issue, IssueEvent::Raised, null));

    $job->failed(new RuntimeException(str_repeat('x', 600)));

    expect($channel->refresh()->last_error)->toHaveLength(500);

    $job->failed(null);
    expect($channel->refresh()->last_error)->toBe('Delivery failed.');
});

it('goes on the queue connection set for notifications', function (): void {
    $issue = Issue::factory()->create();
    $channel = NotificationChannel::factory()->create();
    $message = IssueMessage::about($issue, IssueEvent::Raised, null);

    expect(new DeliverIssueNotification($channel, $message)->connection)->toBeNull();

    config(['meridian.notifications.connection' => 'redis']);
    expect(new DeliverIssueNotification($channel, $message)->connection)->toBe('redis');
});
