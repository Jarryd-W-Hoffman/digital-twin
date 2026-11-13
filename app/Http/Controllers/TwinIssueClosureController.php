<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CloseIssue;
use App\Actions\ReopenIssue;
use App\Models\Issue;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Closing an issue once it's dealt with, and opening it again.
 */
final readonly class TwinIssueClosureController
{
    public function store(Twin $twin, Issue $issue, #[CurrentUser] User $user, CloseIssue $action): RedirectResponse
    {
        Gate::authorize('close', $issue);

        $action->handle($issue, $user);

        return back();
    }

    public function destroy(Twin $twin, Issue $issue, ReopenIssue $action): RedirectResponse
    {
        Gate::authorize('close', $issue);

        $action->handle($issue);

        return back();
    }
}
