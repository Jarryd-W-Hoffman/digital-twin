<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A twin's alert log: every alert its gateway raised, newest first, with
 * when it cleared and who acknowledged it.
 */
final readonly class TwinAlertController
{
    public function index(Request $request, Twin $twin, #[CurrentUser] User $user): Response
    {
        Gate::authorize('view', $twin);

        $openOnly = $request->query('show') === 'open';
        $page = ($openOnly ? $twin->openAlerts() : $twin->alerts())
            ->with('acknowledger:id,name')
            ->latest('raised_at')
            ->latest('id')
            ->simplePaginate(50)
            ->withQueryString();

        return Inertia::render('twins/alerts', [
            'twin' => ['id' => $twin->id, 'name' => $twin->name],
            'alerts' => collect($page->items())->map(fn (Alert $alert): array => [
                'id' => $alert->id,
                'rule' => $alert->rule,
                'asset' => $alert->asset,
                'severity' => $alert->severity->value,
                'message' => $alert->message,
                'value' => $alert->value,
                'raisedAt' => $alert->raised_at->toIso8601String(),
                'clearedAt' => $alert->cleared_at?->toIso8601String(),
                'acknowledgedAt' => $alert->acknowledged_at?->toIso8601String(),
                'acknowledgedBy' => $alert->acknowledger?->name,
            ]),
            'pages' => ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'show' => $openOnly ? 'open' : 'all',
            'hasGateway' => $twin->gateway_token_hash !== null,
            'canAcknowledge' => $user->can('operate', $twin),
        ]);
    }
}
