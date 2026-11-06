<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class SwitchOrganisation
{
    public function handle(User $user, Organisation $organisation): void
    {
        throw_if(! $user->roleIn($organisation) instanceof OrganisationRole, AuthorizationException::class);

        $user->forceFill(['current_organisation_id' => $organisation->id])->save();
    }
}
