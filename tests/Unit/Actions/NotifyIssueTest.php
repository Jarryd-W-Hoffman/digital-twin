<?php

declare(strict_types=1);

use App\Actions\NotifyIssue;
use App\Enums\IssueEvent;
use App\Jobs\DeliverIssueNotification;
use App\Models\Issue;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('queues a delivery to each channel that takes issues and is not in its quiet hours', function (): void {
    Queue::fake();
    $this->travelTo(now()->setTime(12, 0));
    $issue = Issue::factory()->create();
    $project = $issue->twin->project;
    $takes = NotificationChannel::factory()->for($project)->issues()->create();
    NotificationChannel::factory()->for($project)->issues()->quiet('11:00', '13:00')->create();
    NotificationChannel::factory()->for($project)->create();
    NotificationChannel::factory()->issues()->create();
    $by = User::factory()->create(['name' => 'Ana']);

    resolve(NotifyIssue::class)->handle($issue, IssueEvent::Raised, $by);

    Queue::assertPushed(DeliverIssueNotification::class, 1);
    Queue::assertPushed(fn (DeliverIssueNotification $job): bool => $job->channel->is($takes)
        && $job->message->issueId === $issue->id
        && $job->message->by === 'Ana');
});

it('does nothing when no channel takes issues', function (): void {
    Queue::fake();
    $issue = Issue::factory()->create();
    NotificationChannel::factory()->for($issue->twin->project)->create();

    resolve(NotifyIssue::class)->handle($issue, IssueEvent::Closed, null);

    Queue::assertNothingPushed();
});
