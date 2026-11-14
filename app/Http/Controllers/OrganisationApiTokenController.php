<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateApiToken;
use App\Actions\RevokeApiToken;
use App\Enums\ApiTokenAccess;
use App\Http\Requests\StoreApiTokenRequest;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current organisation's API tokens: members see which exist and when
 * they were last used; admins and owners make and revoke them.
 */
final readonly class OrganisationApiTokenController
{
    public function index(#[CurrentUser] User $user): Response
    {
        $organisation = $user->organisation();
        Gate::authorize('view', $organisation);

        return Inertia::render('organisation/api-tokens', [
            'currentOrganisation' => ['id' => $organisation->id, 'name' => $organisation->name],
            'tokens' => $organisation->apiTokens()->with('creator:id,name')->latest()->latest('id')->get()
                ->map(fn (ApiToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'masked' => $token->masked(),
                    'access' => $token->access->label(),
                    'createdBy' => $token->creator?->name,
                    'createdAt' => $token->created_at->toIso8601String(),
                    'expiresAt' => $token->expires_at?->toIso8601String(),
                    'expired' => $token->isExpired(),
                    'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                ]),
            'accessLevels' => collect(ApiTokenAccess::cases())->map(fn (ApiTokenAccess $access): array => ['value' => $access->value, 'label' => $access->label()]),
            'baseUrl' => url('/api/v1'),
            'canManage' => Gate::allows('manageApiTokens', $organisation),
        ]);
    }

    public function store(StoreApiTokenRequest $request, #[CurrentUser] User $user, CreateApiToken $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('manageApiTokens', $organisation);

        [$token, $secret] = $action->handle($organisation, $user, $request->string('name')->value(), $request->access(), $request->expiresAt());

        // Shown once: only the hash is kept.
        Inertia::flash('apiToken', ['tokenId' => $token->id, 'token' => $secret]);

        return to_route('organisation.api-tokens.index');
    }

    public function destroy(ApiToken $apiToken, RevokeApiToken $action): RedirectResponse
    {
        Gate::authorize('manageApiTokens', $apiToken->organisation);

        $action->handle($apiToken);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return to_route('organisation.api-tokens.index');
    }
}
