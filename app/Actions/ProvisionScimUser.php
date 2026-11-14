<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\ScimError;
use App\Models\ScimUser;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\ScimAttributes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ProvisionScimUser
{
    public function __construct(private CreateUser $users, private SyncScimMembership $membership) {}

    /**
     * Takes on someone the identity provider provisions. An address in the
     * connection's domains that already has an account is that account;
     * otherwise one is made, which they sign in to through single sign-on.
     *
     * @throws ScimError
     */
    public function handle(SsoConnection $connection, ScimAttributes $attributes): ScimUser
    {
        $address = $attributes->address();

        throw_unless($connection->covers($address), ScimError::class, 400, __(":address isn't in :organisation's single sign-on domains.", ['address' => $address, 'organisation' => $connection->organisation->name]), 'invalidValue');
        throw_if($connection->scimUsers()->where('user_name', $attributes->userName)->exists(), ScimError::class, 409, __('A user with that userName is already provisioned.'), 'uniqueness');

        return DB::transaction(function () use ($connection, $attributes, $address): ScimUser {
            $user = User::query()->where('email', $address)->first();
            $created = ! $user instanceof User;

            throw_if(! $created && $connection->scimUsers()->where('user_id', $user->id)->exists(), ScimError::class, 409, __(':address is already provisioned under another userName.', ['address' => $address]), 'uniqueness');

            $user ??= $this->users->handle([
                'name' => $attributes->name ?? Str::before($address, '@'),
                'email' => $address,
                'email_verified_at' => now(),
            ], Str::password(48));

            $scimUser = $connection->scimUsers()->create([
                'user_id' => $user->id,
                'user_name' => $attributes->userName,
                'external_id' => $attributes->externalId,
                'active' => $attributes->active,
                'created_account' => $created,
            ]);

            $this->membership->handle($scimUser, $attributes->active);

            return $scimUser;
        });
    }
}
