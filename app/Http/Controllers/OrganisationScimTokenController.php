<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateScimToken;
use App\Actions\RevokeScimToken;
use App\Models\SsoConnection;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The token an organisation's owners give its identity provider to
 * provision people with.
 */
final readonly class OrganisationScimTokenController
{
    public function store(#[CurrentUser] User $user, CreateScimToken $action): RedirectResponse
    {
        $connection = $this->connection($user);

        // Shown once: only the hash is kept.
        Inertia::flash('scimToken', $action->handle($connection));

        return to_route('organisation.sso.show');
    }

    public function destroy(#[CurrentUser] User $user, RevokeScimToken $action): RedirectResponse
    {
        $action->handle($this->connection($user));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Provisioning token revoked.')]);

        return to_route('organisation.sso.show');
    }

    private function connection(User $user): SsoConnection
    {
        $organisation = $user->organisation();
        Gate::authorize('manageSso', $organisation);

        $connection = $organisation->ssoConnection;
        abort_unless($connection instanceof SsoConnection, 404);

        return $connection;
    }
}
