<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\Membership;
use App\Models\ScimUser;

final readonly class SyncScimMembership
{
    public function __construct(private RemoveMember $remove, private RecordAuditEvent $audit) {}

    /**
     * Makes their membership follow whether the provider has them active: a
     * newcomer joins with the connection's default role, and someone
     * deactivated leaves. Owners stay, so the provider can't lock out the
     * people who manage it.
     */
    public function handle(ScimUser $scimUser, bool $active): void
    {
        $connection = $scimUser->connection;
        $organisation = $connection->organisation;
        $user = $scimUser->user;
        $membership = $organisation->memberships()->where('user_id', $user->id)->first();

        if ($active && ! $membership instanceof Membership) {
            $organisation->memberships()->create(['user_id' => $user->id, 'role' => $connection->default_role]);
            $this->audit->handle(AuditAction::MemberAdded, $organisation, $user->email, ['role' => $connection->default_role->value, 'via' => 'provisioning']);

            if ($user->current_organisation_id === null) {
                $user->forceFill(['current_organisation_id' => $organisation->id])->save();
            }

            return;
        }

        if ($active || ! $membership instanceof Membership) {
            return;
        }

        if ($membership->role === OrganisationRole::Owner) {
            $this->audit->handle(AuditAction::ScimOwnerKept, $organisation, $user->email);

            return;
        }

        $this->remove->handle($membership);
    }
}
