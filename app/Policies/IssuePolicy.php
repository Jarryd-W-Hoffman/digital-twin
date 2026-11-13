<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganisationRole;
use App\Models\Issue;
use App\Models\User;

/**
 * Anyone who can see a twin raises and discusses its issues. Whoever raised
 * an issue can give it to someone, close and delete it; whoever it's given
 * to can close it. Operators assign and close anyone's, and editors delete
 * anyone's. Only while they're in the twin's organisation: someone who has
 * left keeps none of it.
 */
final readonly class IssuePolicy
{
    public function assign(User $user, Issue $issue): bool
    {
        $role = $this->role($user, $issue);

        return $role instanceof OrganisationRole && ($issue->author_id === $user->id || $role->canOperate());
    }

    public function close(User $user, Issue $issue): bool
    {
        $role = $this->role($user, $issue);

        return $role instanceof OrganisationRole
            && ($issue->author_id === $user->id || $issue->assignee_id === $user->id || $role->canOperate());
    }

    public function delete(User $user, Issue $issue): bool
    {
        $role = $this->role($user, $issue);

        return $role instanceof OrganisationRole && ($issue->author_id === $user->id || $role->canEditTwins());
    }

    private function role(User $user, Issue $issue): ?OrganisationRole
    {
        return $user->roleIn($issue->twin->organisation);
    }
}
