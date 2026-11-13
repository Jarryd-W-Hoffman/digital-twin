<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;

final readonly class UpdateMemberRole
{
    public function __construct(private EnsureAnotherOwner $owners, private RecordAuditEvent $audit) {}

    public function handle(Membership $membership, OrganisationRole $role): void
    {
        DB::transaction(function () use ($membership, $role): void {
            if ($membership->role === OrganisationRole::Owner && $role !== OrganisationRole::Owner) {
                $this->owners->handle($membership);
            }

            $from = $membership->role;
            $membership->update(['role' => $role]);

            $this->audit->handle(AuditAction::MemberRoleChanged, $membership->organisation, $membership->user->email, ['from' => $from->value, 'to' => $role->value]);
        });
    }
}
