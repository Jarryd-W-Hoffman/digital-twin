<?php

declare(strict_types=1);

use App\Actions\CloseIssue;
use App\Actions\ReopenIssue;
use App\Enums\IssueEvent;
use App\Jobs\DeliverIssueNotification;
use App\Models\Issue;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('closes an issue once, keeping who closed it first, and says so once', function (): void {
    Queue::fake();
    $issue = Issue::factory()->create();
    NotificationChannel::factory()->for($issue->twin->project)->issues()->create();
    [$first, $second] = User::factory()->count(2)->create();

    resolve(CloseIssue::class)->handle($issue, $first);
    resolve(CloseIssue::class)->handle($issue, $second);

    expect($issue->fresh()?->closed_by)->toBe($first->id)
        ->and($issue->fresh()?->closed_at)->not->toBeNull();
    Queue::assertPushed(DeliverIssueNotification::class, 1);
    Queue::assertPushed(fn (DeliverIssueNotification $job): bool => $job->message->event === IssueEvent::Closed && $job->message->by === $first->name);
});

it('opens a closed issue again', function (): void {
    $issue = Issue::factory()->closed()->create();

    resolve(ReopenIssue::class)->handle($issue);

    expect($issue->fresh()?->closed_at)->toBeNull()
        ->and($issue->fresh()?->closed_by)->toBeNull();
});
