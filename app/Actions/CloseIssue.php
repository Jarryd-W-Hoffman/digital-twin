<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\User;

/**
 * Marks an issue dealt with, by whoever closed it first.
 */
final readonly class CloseIssue
{
    public function handle(Issue $issue, User $user): void
    {
        Issue::query()
            ->whereKey($issue->id)
            ->whereNull('closed_at')
            ->update(['closed_at' => now(), 'closed_by' => $user->id, 'updated_at' => now()]);
    }
}
