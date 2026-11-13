<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\IssueEvent;
use App\Models\Issue;
use App\Models\User;

/**
 * Marks an issue dealt with, by whoever closed it first, and tells the
 * channels that take issue updates.
 */
final readonly class CloseIssue
{
    public function __construct(private NotifyIssue $notify)
    {
        //
    }

    public function handle(Issue $issue, User $user): void
    {
        $closed = Issue::query()
            ->whereKey($issue->id)
            ->whereNull('closed_at')
            ->update(['closed_at' => now(), 'closed_by' => $user->id, 'updated_at' => now()]);

        if ($closed > 0) {
            $this->notify->handle($issue, IssueEvent::Closed, $user);
        }
    }
}
