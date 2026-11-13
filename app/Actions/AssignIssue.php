<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\IssueEvent;
use App\Mail\IssueMail;
use App\Models\Issue;
use App\Models\User;
use App\Support\IssueMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;

/**
 * Gives an issue to someone to deal with, by a date, or takes it back. A
 * new assignee is emailed, unless they took it themselves, and channels
 * that take issue updates are told.
 */
final readonly class AssignIssue
{
    public function __construct(private NotifyIssue $notify)
    {
        //
    }

    public function handle(Issue $issue, User $by, ?User $assignee, ?CarbonInterface $dueOn): void
    {
        $reassigned = $assignee instanceof User && $issue->assignee_id !== $assignee->id;

        $issue->update(['assignee_id' => $assignee?->id, 'due_on' => $dueOn]);
        $issue->setRelation('assignee', $assignee);

        if (! $reassigned) {
            return;
        }

        $this->notify->handle($issue, IssueEvent::Assigned, $by);

        if ($assignee->id !== $by->id) {
            Mail::to($assignee)->queue(new IssueMail(IssueMessage::about($issue, IssueEvent::Assigned, $by), toAssignee: true));
        }
    }
}
