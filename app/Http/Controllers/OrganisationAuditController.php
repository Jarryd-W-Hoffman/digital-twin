<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\IndexAuditEventsRequest;
use App\Http\Resources\AuditEventResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current organisation's audit log, for its admins and owners: who
 * did what, newest first, 50 at a time.
 */
final readonly class OrganisationAuditController
{
    public function index(IndexAuditEventsRequest $request, #[CurrentUser] User $user): Response
    {
        $organisation = $user->organisation();
        Gate::authorize('viewAudit', $organisation);

        $page = $request->events($organisation)->cursorPaginate(50)->withQueryString();

        return Inertia::render('organisation/audit', [
            'currentOrganisation' => ['id' => $organisation->id, 'name' => $organisation->name],
            'events' => AuditEventResource::collection($page->items())->resolve($request),
            'areas' => collect(AuditAction::areas())->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
            'area' => $request->area(),
            'next' => $page->nextPageUrl(),
            'previous' => $page->previousPageUrl(),
            'retentionDays' => config()->integer('meridian.audit.retention_days'),
        ]);
    }
}
