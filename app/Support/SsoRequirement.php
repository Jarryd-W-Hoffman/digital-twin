<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\SsoConnection;
use App\Models\User;

/**
 * An organisation that requires single sign-on lets its members other than
 * owners work in it only once this session has signed in through its
 * identity provider. Owners are exempt, so a broken provider can't lock
 * everyone out. Outside a request (the queue, the console) nothing is held
 * back, since nobody is signing in there.
 */
final readonly class SsoRequirement
{
    /**
     * The session key listing the connections this session signed in through.
     */
    public const string SESSION = 'sso.verified';

    public function blocks(User $user, Organisation $organisation, OrganisationRole $role): bool
    {
        $connection = $organisation->ssoConnection;

        if ($role === OrganisationRole::Owner || ! $connection instanceof SsoConnection || ! $connection->required) {
            return false;
        }

        $request = request();
        if (! $request->hasSession()) {
            return false;
        }

        /** @var list<string> $verified */
        $verified = $request->session()->get(self::SESSION, []);

        return ! in_array($connection->id, $verified, true);
    }

    /**
     * Notes that this session signed in through a connection.
     */
    public function verify(SsoConnection $connection): void
    {
        /** @var list<string> $verified */
        $verified = request()->session()->get(self::SESSION, []);

        request()->session()->put(self::SESSION, array_values(array_unique([...$verified, $connection->id])));
    }

    /**
     * Forgets it, when its link is removed.
     */
    public function forget(SsoConnection $connection): void
    {
        /** @var list<string> $verified */
        $verified = request()->session()->get(self::SESSION, []);

        request()->session()->put(self::SESSION, array_values(array_diff($verified, [$connection->id])));
    }
}
