<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SetOpeningView;
use App\Models\SavedView;
use App\Models\Twin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The view a twin opens at, chosen by those who edit it.
 */
final readonly class TwinOpeningViewController
{
    public function store(Twin $twin, SavedView $savedView, SetOpeningView $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($twin, $savedView);

        return back();
    }

    public function destroy(Twin $twin, SetOpeningView $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($twin, null);

        return back();
    }
}
