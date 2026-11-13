<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssignIssue;
use App\Http\Requests\UpdateIssueAssignmentRequest;
use App\Models\Issue;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Giving an issue to someone to deal with, by a date.
 */
final readonly class TwinIssueAssignmentController
{
    public function update(UpdateIssueAssignmentRequest $request, Twin $twin, Issue $issue, #[CurrentUser] User $user, AssignIssue $action): RedirectResponse
    {
        Gate::authorize('assign', $issue);

        $action->handle($issue, $user, $request->assignee(), $request->dueOn());

        return back();
    }
}
