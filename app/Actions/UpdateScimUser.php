<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\ScimError;
use App\Models\ScimUser;
use App\Support\ScimAttributes;
use Illuminate\Support\Facades\DB;

final readonly class UpdateScimUser
{
    public function __construct(private SyncScimMembership $membership) {}

    /**
     * Follows the provider's changes: their userName and externalId, their
     * name on an account it made, and whether they're active. An account's
     * email address is the person's own and doesn't change.
     *
     * @throws ScimError
     */
    public function handle(ScimUser $scimUser, ScimAttributes $attributes): ScimUser
    {
        $taken = $scimUser->connection->scimUsers()->where('user_name', $attributes->userName)->whereKeyNot($scimUser->id)->exists();
        throw_if($taken, ScimError::class, 409, __('A user with that userName is already provisioned.'), 'uniqueness');

        return DB::transaction(function () use ($scimUser, $attributes): ScimUser {
            $scimUser->update([
                'user_name' => $attributes->userName,
                'external_id' => $attributes->externalId,
                'active' => $attributes->active,
            ]);

            if ($scimUser->created_account && $attributes->name !== null) {
                $scimUser->user->update(['name' => $attributes->name]);
            }

            $this->membership->handle($scimUser, $attributes->active);

            return $scimUser;
        });
    }
}
