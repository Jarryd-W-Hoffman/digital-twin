<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartSsoSignIn;
use App\Actions\UnlinkSsoIdentity;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The identity providers someone signs in through, linked while they're
 * signed in the usual way.
 */
final readonly class UserSsoIdentityController
{
    public function index(#[CurrentUser] User $user): Response
    {
        $organisation = $user->currentOrganisation;
        $connection = $organisation?->ssoConnection;

        return Inertia::render('user-sso/index', [
            'identities' => $user->ssoIdentities()->with('connection.organisation:id,name')->latest('id')->get()
                ->map(fn (SsoIdentity $identity): array => [
                    'id' => $identity->id,
                    'organisation' => $identity->connection->organisation->name,
                    'email' => $identity->email,
                    'lastSignedInAt' => $identity->last_signed_in_at?->toIso8601String(),
                ]),
            'available' => $connection instanceof SsoConnection && ! $user->ssoIdentities()->where('sso_connection_id', $connection->id)->exists()
                ? ['organisation' => $organisation->name]
                : null,
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, StartSsoSignIn $action): SymfonyResponse
    {
        $connection = $user->currentOrganisation?->ssoConnection;
        abort_unless($connection instanceof SsoConnection, 404);

        [$url, $flow] = $action->handle($connection, $user->email, $user);
        $request->session()->put(StartSsoSignIn::SESSION, $flow);

        return Inertia::location($url);
    }

    public function destroy(#[CurrentUser] User $user, SsoIdentity $identity, UnlinkSsoIdentity $action): RedirectResponse
    {
        abort_unless($identity->user_id === $user->id, 404);

        $action->handle($identity);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unlinked. Sign in with your password or passkey from now on.')]);

        return to_route('user-sso.index');
    }
}
