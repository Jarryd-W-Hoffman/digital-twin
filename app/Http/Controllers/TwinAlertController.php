<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\Issue;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A twin's alert log: every alert its gateway raised, newest first, with
 * when it cleared, who acknowledged it, who was told, and the issues
 * raised to follow it up.
 */
final readonly class TwinAlertController
{
    public function index(Request $request, Twin $twin, #[CurrentUser] User $user): Response
    {
        Gate::authorize('view', $twin);

        $openOnly = $request->query('show') === 'open';
        $page = ($openOnly ? $twin->openAlerts() : $twin->alerts())
            ->with([
                'acknowledger:id,name',
                'notifications' => fn (HasMany $query): HasMany => $query->oldest('created_at'),
                'issues' => fn (HasMany $query): HasMany => $query->select(['id', 'alert_id', 'number', 'closed_at'])->oldest('number'),
            ])
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
                // The issues raised to follow it up.
                'issues' => $alert->issues->map(fn (Issue $issue): array => [
                    'id' => $issue->id,
                    'number' => $issue->number,
                    'closed' => $issue->closed_at !== null,
                ]),
                'notifications' => $alert->notifications->map(fn (AlertNotification $notification): array => [
                    'channel' => $notification->channel_name,
                    'event' => $notification->event->value,
                    'status' => $notification->status->value,
                    'error' => $notification->error,
                ]),
            ]),
            'pages' => ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'show' => $openOnly ? 'open' : 'all',
            'hasGateway' => $twin->gateway_token_hash !== null,
            'canAcknowledge' => $user->can('operate', $twin),
        ]);
    }
}
