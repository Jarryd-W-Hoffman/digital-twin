<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Requests\IndexApiTwinsRequest;
use App\Http\Resources\TwinResource;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The organisation's twins, for API tokens: all of them or one project's,
 * and one with its assets and what's open on it.
 */
final readonly class ApiTwinController
{
    public function index(IndexApiTwinsRequest $request): AnonymousResourceCollection
    {
        $token = AuthenticateApiToken::token($request);

        return TwinResource::collection(Twin::query()
            ->where('organisation_id', $token->organisation_id)
            ->when($request->project(), fn (Builder $query, string $project): Builder => $query->where('project_id', $project))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString());
    }

    public function show(Request $request, Twin $twin): TwinResource
    {
        abort_unless(AuthenticateApiToken::token($request)->reaches($twin), 404);

        return new TwinResource($twin
            ->load('latestVersion')
            ->loadCount(['openAlerts', 'issues as open_issues_count' => fn (Builder $query): Builder => $query->whereNull('closed_at')]));
    }
}
