<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\User;

final readonly class OrganisationPolicy
{
    public function view(User $user, Organisation $organisation): bool
    {
        return $user->roleIn($organisation) instanceof OrganisationRole;
    }

    public function manageMembers(User $user, Organisation $organisation): bool
    {
        return $user->roleIn($organisation)?->canManageMembers() ?? false;
    }

    /**
     * Adding to and deleting from the type library: the people who edit twins.
     */
    public function manageTypes(User $user, Organisation $organisation): bool
    {
        return $user->roleIn($organisation)?->canEditTwins() ?? false;
    }

    /**
     * Making and revoking API tokens, which read everything the organisation
     * holds: the people who manage its members.
     */
    public function manageApiTokens(User $user, Organisation $organisation): bool
    {
        return $user->roleIn($organisation)?->canManageMembers() ?? false;
    }
}
