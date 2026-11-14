<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\IssueEvent;
use App\Models\ApiToken;
use App\Models\Issue;
use App\Models\User;
use App\Support\Actor;

/**
 * Marks an issue dealt with, by whoever closed it first, and tells the
 * channels that take issue updates.
 */
final readonly class CloseIssue
{
    public function __construct(private NotifyIssue $notify, private RecordAuditEvent $audit)
    {
        //
    }

    public function handle(Issue $issue, User|ApiToken $user): void
    {
        $closed = Issue::query()
            ->whereKey($issue->id)
            ->whereNull('closed_at')
            ->update(['closed_at' => now(), 'closed_by' => Actor::columns($user)[0], 'closed_by_api' => Actor::columns($user)[1], 'updated_at' => now()]);

        if ($closed > 0) {
            $this->audit->handle(AuditAction::IssueClosed, $issue->twin, sprintf('#%d %s', $issue->number, $issue->title));
            $this->notify->handle($issue, IssueEvent::Closed, $user);
        }
    }
}
