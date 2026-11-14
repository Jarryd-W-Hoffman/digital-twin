<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddIssueComment;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Requests\StoreIssueCommentRequest;
use App\Http\Resources\IssueCommentResource;
use App\Models\Issue;
use App\Models\Twin;
use Illuminate\Http\JsonResponse;

/**
 * Comments added to an issue by another system, signed with its token.
 */
final readonly class ApiIssueCommentController
{
    public function store(StoreIssueCommentRequest $request, Twin $twin, Issue $issue, AddIssueComment $action): JsonResponse
    {
        $token = AuthenticateApiToken::token($request);
        abort_unless($token->reaches($twin), 404);

        $comment = $action->handle($issue, $token, $request->body(), $request->photo());
        $comment->setRelation('issue', $issue);

        return new IssueCommentResource($comment)->response()->setStatusCode(201);
    }
}
