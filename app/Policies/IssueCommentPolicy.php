<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IssueComment;
use App\Models\User;

/**
 * A comment is deleted by whoever wrote it, or by an editor.
 */
final readonly class IssueCommentPolicy
{
    public function delete(User $user, IssueComment $comment): bool
    {
        return $comment->author_id === $user->id
            || ($user->roleIn($comment->issue->twin->organisation)?->canEditTwins() ?? false);
    }
}
