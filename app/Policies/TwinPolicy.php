<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\Twin;
use App\Models\User;

final readonly class TwinPolicy
{
    public function create(User $user, Organisation $organisation): bool
    {
        return $user->roleIn($organisation)?->canEditTwins() ?? false;
    }

    public function view(User $user, Twin $twin): bool
    {
        return $user->roleIn($twin->organisation) instanceof OrganisationRole;
    }

    public function update(User $user, Twin $twin): bool
    {
        return $user->roleIn($twin->organisation)?->canEditTwins() ?? false;
    }

    public function operate(User $user, Twin $twin): bool
    {
        return $user->roleIn($twin->organisation)?->canOperate() ?? false;
    }

    public function delete(User $user, Twin $twin): bool
    {
        return $this->update($user, $twin);
    }
}
