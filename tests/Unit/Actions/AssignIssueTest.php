<?php

declare(strict_types=1);

use App\Actions\AssignIssue;
use App\Enums\IssueEvent;
use App\Jobs\DeliverIssueNotification;
use App\Mail\IssueMail;
use App\Models\Issue;
use App\Models\NotificationChannel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('tells the channels and emails a new assignee', function (): void {
    Mail::fake();
    Queue::fake();
    $issue = Issue::factory()->create();
    NotificationChannel::factory()->for($issue->twin->project)->issues()->create();
    [$by, $to] = User::factory()->count(2)->create();

    resolve(AssignIssue::class)->handle($issue, $by, $to, CarbonImmutable::parse('2026-12-01'));

    Queue::assertPushed(fn (DeliverIssueNotification $job): bool => $job->message->event === IssueEvent::Assigned
        && $job->message->assignee === $to->name
        && $job->message->dueOn?->format('Y-m-d') === '2026-12-01');
    Mail::assertQueued(IssueMail::class, fn (IssueMail $mail): bool => $mail->hasTo($to->email) && $mail->issue->by === $by->name);
});

it("doesn't email someone who takes an issue themselves", function (): void {
    Mail::fake();
    Queue::fake();
    $issue = Issue::factory()->create();
    NotificationChannel::factory()->for($issue->twin->project)->issues()->create();
    $user = User::factory()->create();

    resolve(AssignIssue::class)->handle($issue, $user, $user, null);

    Queue::assertPushed(DeliverIssueNotification::class, 1);
    Mail::assertNothingQueued();
});

it('tells nobody when only the due date changes, or the issue is taken back', function (): void {
    Mail::fake();
    Queue::fake();
    $to = User::factory()->create();
    $issue = Issue::factory()->assigned($to)->create();
    NotificationChannel::factory()->for($issue->twin->project)->issues()->create();
    $by = User::factory()->create();

    resolve(AssignIssue::class)->handle($issue, $by, $to, CarbonImmutable::parse('2027-01-01'));
    expect($issue->fresh()?->due_on?->format('Y-m-d'))->toBe('2027-01-01');

    resolve(AssignIssue::class)->handle($issue, $by, null, null);
    expect($issue->fresh()?->assignee_id)->toBeNull();

    Queue::assertNothingPushed();
    Mail::assertNothingQueued();
});
