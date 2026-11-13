<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Issue;

/**
 * Opens a closed issue again, when it turns out not to be dealt with.
 */
final readonly class ReopenIssue
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Issue $issue): void
    {
        $issue->update(['closed_at' => null, 'closed_by' => null, 'closed_by_api' => null]);

        $this->audit->handle(AuditAction::IssueReopened, $issue->twin, sprintf('#%d %s', $issue->number, $issue->title));
    }
}
