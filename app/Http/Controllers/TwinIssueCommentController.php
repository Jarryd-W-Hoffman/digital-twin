<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddIssueComment;
use App\Actions\DeleteIssueComment;
use App\Http\Requests\StoreIssueCommentRequest;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The conversation on an issue: anyone who can see the twin joins in.
 */
final readonly class TwinIssueCommentController
{
    public function store(StoreIssueCommentRequest $request, Twin $twin, Issue $issue, #[CurrentUser] User $user, AddIssueComment $action): RedirectResponse
    {
        Gate::authorize('view', $twin);

        $action->handle($issue, $user, $request->body(), $request->photo());

        return back();
    }

    public function destroy(Twin $twin, Issue $issue, IssueComment $comment, DeleteIssueComment $action): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $action->handle($comment);

        return back();
    }
}
