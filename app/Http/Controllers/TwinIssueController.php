<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateIssue;
use App\Actions\DeleteIssue;
use App\Http\Requests\StoreIssueRequest;
use App\Models\Issue;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Raising an issue in a twin's scene, which anyone who can see the twin
 * may do, and deleting one.
 */
final readonly class TwinIssueController
{
    public function store(StoreIssueRequest $request, Twin $twin, #[CurrentUser] User $user, CreateIssue $action): RedirectResponse
    {
        Gate::authorize('view', $twin);

        $issue = $action->handle(
            $twin,
            $user,
            $request->string('title')->value(),
            $request->at(),
            $request->view(),
            $request->body(),
            $request->photo(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Issue :number raised.', ['number' => $issue->number])]);

        return to_route('twins.show', [$twin, 'issue' => $issue->id]);
    }

    public function destroy(Twin $twin, Issue $issue, DeleteIssue $action): RedirectResponse
    {
        Gate::authorize('delete', $issue);

        $action->handle($issue);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Issue deleted.')]);

        return to_route('twins.show', $twin);
    }
}
