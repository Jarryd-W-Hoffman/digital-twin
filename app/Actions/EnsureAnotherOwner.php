<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrganisationRole;
use App\Models\Membership;
use Illuminate\Validation\ValidationException;

final readonly class EnsureAnotherOwner
{
    /**
     * Refuses a change that would leave the membership's organisation without an owner.
     */
    public function handle(Membership $membership): void
    {
        $hasOthers = Membership::query()
            ->where('organisation_id', $membership->organisation_id)
            ->where('role', OrganisationRole::Owner)
            ->whereKeyNot($membership->id)
            ->lockForUpdate()
            ->exists();

        throw_unless($hasOthers, ValidationException::withMessages([
            'role' => __('An organisation needs at least one owner.'),
        ]));
    }
}
