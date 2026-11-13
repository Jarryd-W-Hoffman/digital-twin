<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Requests\IndexApiIssuesRequest;
use App\Http\Resources\IssueResource;
use App\Models\Issue;
use App\Models\Twin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The issues pinned in a twin, for API tokens: open, closed or all, newest
 * first, and one with its comments.
 */
final readonly class ApiTwinIssueController
{
    public function index(IndexApiIssuesRequest $request, Twin $twin): AnonymousResourceCollection
    {
        abort_unless(AuthenticateApiToken::token($request)->reaches($twin), 404);

        return IssueResource::collection(Issue::query()
            ->where('twin_id', $twin->id)
            ->with(['author:id,name', 'assignee:id,name', 'closer:id,name'])
            ->when($request->state() === 'open', fn (Builder $query): Builder => $query->whereNull('closed_at'))
            ->when($request->state() === 'closed', fn (Builder $query): Builder => $query->whereNotNull('closed_at'))
            ->orderByDesc('number')
            ->paginate(50)
            ->withQueryString());
    }

    public function show(Request $request, Twin $twin, Issue $issue): IssueResource
    {
        abort_unless(AuthenticateApiToken::token($request)->reaches($twin), 404);

        return new IssueResource($issue->load([
            'author:id,name',
            'assignee:id,name',
            'closer:id,name',
            'comments' => fn (HasMany $query): HasMany => $query->with('author:id,name')->oldest()->oldest('id'),
        ]));
    }
}
