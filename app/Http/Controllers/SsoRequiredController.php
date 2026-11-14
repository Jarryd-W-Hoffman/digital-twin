<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartSsoSignIn;
use App\Enums\OrganisationRole;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\SsoRequirement;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Where someone lands when their organisation requires single sign-on and
 * this session hasn't signed in through it: one step through the identity
 * provider, which also links their account if it isn't yet.
 */
final readonly class SsoRequiredController
{
    public function show(#[CurrentUser] User $user, SsoRequirement $requirement): Response|RedirectResponse
    {
        $organisation = $user->organisation();
        $role = $user->roleIn($organisation);

        if (! $role instanceof OrganisationRole || ! $requirement->blocks($user, $organisation, $role)) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return Inertia::render('sso/required', [
            'organisation' => $organisation->name,
            'linked' => $user->ssoIdentities()->where('sso_connection_id', $organisation->ssoConnection?->id)->exists(),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, StartSsoSignIn $action): SymfonyResponse
    {
        $connection = $user->organisation()->ssoConnection;
        abort_unless($connection instanceof SsoConnection, 404);

        $then = $request->session()->pull('url.intended');
        [$url, $flow] = $action->handle($connection, $user->email, $user, is_string($then) ? $then : route('dashboard'));
        $request->session()->put(StartSsoSignIn::SESSION, $flow);

        return Inertia::location($url);
    }
}
