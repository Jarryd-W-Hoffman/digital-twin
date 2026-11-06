<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IssueGatewayToken;
use App\Actions\RevokeGatewayToken;
use App\Models\Twin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final readonly class TwinGatewayTokenController
{
    public function store(Twin $twin, IssueGatewayToken $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        // Shown once: only the hash is kept.
        Inertia::flash('gatewayToken', $action->handle($twin));

        return to_route('twins.show', $twin);
    }

    public function destroy(Twin $twin, RevokeGatewayToken $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($twin);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Gateway token revoked.')]);

        return to_route('twins.show', $twin);
    }
}
