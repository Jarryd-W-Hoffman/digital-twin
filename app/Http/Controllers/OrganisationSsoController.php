<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteSsoConnection;
use App\Actions\SaveSsoConnection;
use App\Enums\OrganisationRole;
use App\Http\Requests\SaveSsoConnectionRequest;
use App\Models\SsoConnection;
use App\Models\SsoDomain;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current organisation's identity provider, which its owners set up.
 */
final readonly class OrganisationSsoController
{
    public function show(#[CurrentUser] User $user): Response
    {
        $organisation = $user->organisation();
        Gate::authorize('manageSso', $organisation);

        $connection = $organisation->ssoConnection()->with('domains')->first();

        return Inertia::render('organisation/sso', [
            'currentOrganisation' => ['id' => $organisation->id, 'name' => $organisation->name],
            'connection' => $connection instanceof SsoConnection ? [
                'issuer' => $connection->issuer,
                'clientId' => $connection->client_id,
                'domains' => $connection->domains->map(fn (SsoDomain $domain): string => $domain->domain)->implode(', '),
                'required' => $connection->required,
                'autoJoin' => $connection->auto_join,
                'defaultRole' => $connection->default_role->value,
                'linked' => $connection->identities()->count(),
            ] : null,
            'redirectUri' => route('sso.callback'),
            'roles' => collect([OrganisationRole::Viewer, OrganisationRole::Operator])->map(fn (OrganisationRole $role): array => ['value' => $role->value, 'label' => $role->label()]),
        ]);
    }

    public function update(SaveSsoConnectionRequest $request, #[CurrentUser] User $user, SaveSsoConnection $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('manageSso', $organisation);

        $action->handle($organisation, $request->settings());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Single sign-on saved.')]);

        return to_route('organisation.sso.show');
    }

    public function destroy(#[CurrentUser] User $user, DeleteSsoConnection $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('manageSso', $organisation);

        $connection = $organisation->ssoConnection;
        abort_unless($connection instanceof SsoConnection, 404);

        $action->handle($connection);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Single sign-on turned off.')]);

        return to_route('organisation.sso.show');
    }
}
