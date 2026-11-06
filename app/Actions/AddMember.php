<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrganisationRole;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class AddMember
{
    /**
     * Adds an existing user to the organisation. Invitations by email for
     * people without an account come later.
     */
    public function handle(Organisation $organisation, string $email, OrganisationRole $role): Membership
    {
        $user = User::query()->where('email', $email)->first();

        throw_if($user === null, ValidationException::withMessages([
            'email' => __('No one has an account with that email yet.'),
        ]));

        throw_if($user->roleIn($organisation) !== null, ValidationException::withMessages([
            'email' => __('They are already a member.'),
        ]));

        return $organisation->memberships()->create([
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }
}
