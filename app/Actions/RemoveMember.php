<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrganisationRole;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;

final readonly class RemoveMember
{
    public function __construct(private EnsureAnotherOwner $owners) {}

    public function handle(Membership $membership): void
    {
        DB::transaction(function () use ($membership): void {
            if ($membership->role === OrganisationRole::Owner) {
                $this->owners->handle($membership);
            }

            $user = $membership->user;
            $membership->delete();

            if ($user->current_organisation_id === $membership->organisation_id) {
                $next = $user->memberships()->value('organisation_id');
                $user->forceFill(['current_organisation_id' => $next])->save();
            }
        });
    }
}
