<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;

/**
 * Opens a closed issue again, when it turns out not to be dealt with.
 */
final readonly class ReopenIssue
{
    public function handle(Issue $issue): void
    {
        $issue->update(['closed_at' => null, 'closed_by' => null]);
    }
}
