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
        return $user->accessIn($organisation) instanceof OrganisationRole;
    }

    public function manageMembers(User $user, Organisation $organisation): bool
    {
        return $user->accessIn($organisation)?->canManageMembers() ?? false;
    }

    /**
     * Adding to and deleting from the type library: the people who edit twins.
     */
    public function manageTypes(User $user, Organisation $organisation): bool
    {
        return $user->accessIn($organisation)?->canEditTwins() ?? false;
    }

    /**
     * Making and revoking API tokens, which read everything the organisation
     * holds: the people who manage its members.
     */
    public function manageApiTokens(User $user, Organisation $organisation): bool
    {
        return $user->accessIn($organisation)?->canManageMembers() ?? false;
    }

    /**
     * Reading the audit log, which names everyone and everything: the people
     * who manage members.
     */
    public function viewAudit(User $user, Organisation $organisation): bool
    {
        return $user->accessIn($organisation)?->canManageMembers() ?? false;
    }

    /**
     * Turning optional features on and off for everyone: the people who
     * manage members.
     */
    public function manageFeatures(User $user, Organisation $organisation): bool
    {
        return $user->accessIn($organisation)?->canManageMembers() ?? false;
    }

    /**
     * Setting up single sign-on, which decides how everyone in the
     * organisation signs in: its owners.
     */
    public function manageSso(User $user, Organisation $organisation): bool
    {
        return $user->accessIn($organisation) === OrganisationRole::Owner;
    }
}
