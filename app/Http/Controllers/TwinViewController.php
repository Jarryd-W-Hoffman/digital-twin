<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveView;
use App\Actions\UpdateSavedView;
use App\Http\Requests\StoreSavedViewRequest;
use App\Http\Requests\UpdateSavedViewRequest;
use App\Models\SavedView;
use App\Models\Twin;
use App\Models\User;
use App\Support\ViewState;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Views of a twin people save to come back to, and share.
 */
final readonly class TwinViewController
{
    public function store(StoreSavedViewRequest $request, Twin $twin, #[CurrentUser] User $user, SaveView $action): RedirectResponse
    {
        Gate::authorize('view', $twin);

        $view = $action->handle($twin, $user, $request->string('name')->value(), ViewState::from($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('View ":name" saved.', ['name' => $view->name])]);

        return back();
    }

    public function update(UpdateSavedViewRequest $request, Twin $twin, SavedView $savedView, UpdateSavedView $action): RedirectResponse
    {
        Gate::authorize('update', $savedView);

        $shared = $request->has('shared') ? $request->boolean('shared') : null;

        if ($shared !== null && $shared !== $savedView->shared) {
            Gate::authorize('share', $savedView);
        }

        $action->handle(
            $savedView,
            $request->has('name') ? $request->string('name')->value() : null,
            $shared,
            $request->has('state') ? ViewState::from($request) : null,
        );

        return back();
    }

    public function destroy(Twin $twin, SavedView $savedView): RedirectResponse
    {
        Gate::authorize('delete', $savedView);

        $savedView->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('View deleted.')]);

        return back();
    }
}
