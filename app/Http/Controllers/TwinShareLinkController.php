<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateShareLink;
use App\Actions\RevokeShareLink;
use App\Http\Requests\StoreShareLinkRequest;
use App\Models\Twin;
use App\Models\TwinShareLink;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The links an admin or owner makes to show a twin outside the organisation.
 */
final readonly class TwinShareLinkController
{
    public function store(StoreShareLinkRequest $request, Twin $twin, #[CurrentUser] User $user, CreateShareLink $action): RedirectResponse
    {
        Gate::authorize('share', $twin);

        [$link, $token] = $action->handle($twin, $user, $request->string('name')->value(), $request->expiresAt(), $request->boolean('live'));

        // Shown once: only the hash is kept.
        Inertia::flash('shareLink', ['linkId' => $link->id, 'url' => route('share.show', $token)]);

        return back();
    }

    public function destroy(Twin $twin, TwinShareLink $shareLink, RevokeShareLink $action): RedirectResponse
    {
        Gate::authorize('share', $twin);

        $action->handle($shareLink);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Share link revoked.')]);

        return back();
    }
}
