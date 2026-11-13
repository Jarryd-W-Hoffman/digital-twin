<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;

/**
 * Anyone who can see a twin raises and discusses its issues. Whoever raised
 * an issue can close and delete it; operators close anyone's, and editors
 * delete anyone's.
 */
final readonly class IssuePolicy
{
    public function close(User $user, Issue $issue): bool
    {
        return $issue->author_id === $user->id
            || ($user->roleIn($issue->twin->organisation)?->canOperate() ?? false);
    }

    public function delete(User $user, Issue $issue): bool
    {
        return $issue->author_id === $user->id
            || ($user->roleIn($issue->twin->organisation)?->canEditTwins() ?? false);
    }
}
