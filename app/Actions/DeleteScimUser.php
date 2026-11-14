<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ScimUser;
use Illuminate\Support\Facades\DB;

final readonly class DeleteScimUser
{
    public function __construct(private SyncScimMembership $membership) {}

    /**
     * The provider is done with them: they leave the organisation (unless
     * they own it). Their account stays, as for anyone removed.
     */
    public function handle(ScimUser $scimUser): void
    {
        DB::transaction(function () use ($scimUser): void {
            $this->membership->handle($scimUser, false);
            $scimUser->delete();
        });
    }
}
