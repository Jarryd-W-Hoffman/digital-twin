<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganisationRole;
use App\Models\IssueComment;
use App\Models\User;

/**
 * A comment is deleted by whoever wrote it, or by an editor, while they're
 * in the twin's organisation.
 */
final readonly class IssueCommentPolicy
{
    public function delete(User $user, IssueComment $comment): bool
    {
        $role = $user->roleIn($comment->issue->twin->organisation);

        return $role instanceof OrganisationRole && ($comment->author_id === $user->id || $role->canEditTwins());
    }
}
