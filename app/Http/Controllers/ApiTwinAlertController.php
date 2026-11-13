<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Requests\IndexApiAlertsRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Twin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A twin's alert log for API tokens, newest first: all of it, what's still
 * open, or what was raised since a time.
 */
final readonly class ApiTwinAlertController
{
    public function index(IndexApiAlertsRequest $request, Twin $twin): AnonymousResourceCollection
    {
        abort_unless(AuthenticateApiToken::token($request)->reaches($twin), 404);

        return AlertResource::collection(Alert::query()
            ->where('twin_id', $twin->id)
            ->with('acknowledger:id,name')
            ->when($request->boolean('open'), fn (Builder $query): Builder => $query->whereNull('cleared_at'))
            ->when($request->since(), fn (Builder $query, CarbonImmutable $since): Builder => $query->where('raised_at', '>=', $since))
            ->latest('raised_at')
            ->latest('id')
            ->cursorPaginate(50)
            ->withQueryString());
    }
}
