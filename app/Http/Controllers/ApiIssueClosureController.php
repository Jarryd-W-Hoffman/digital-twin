<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CloseIssue;
use App\Actions\ReopenIssue;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Resources\IssueResource;
use App\Models\Issue;
use App\Models\Twin;
use Illuminate\Http\Request;

/**
 * Another system closing an issue, such as when its work order is done,
 * or reopening it. Closing one that's already closed keeps who closed it.
 */
final readonly class ApiIssueClosureController
{
    public function store(Request $request, Twin $twin, Issue $issue, CloseIssue $action): IssueResource
    {
        $token = AuthenticateApiToken::token($request);
        abort_unless($token->reaches($twin), 404);

        $action->handle($issue, $token);

        return new IssueResource($issue->refresh()->load(['author:id,name', 'assignee:id,name', 'closer:id,name']));
    }

    public function destroy(Request $request, Twin $twin, Issue $issue, ReopenIssue $action): IssueResource
    {
        $token = AuthenticateApiToken::token($request);
        abort_unless($token->reaches($twin), 404);

        $action->handle($issue);

        return new IssueResource($issue->refresh()->load(['author:id,name', 'assignee:id,name', 'closer:id,name']));
    }
}
