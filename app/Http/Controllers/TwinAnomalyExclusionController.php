<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateAnomalyExclusion;
use App\Actions\DeleteAnomalyExclusion;
use App\Http\Requests\StoreAnomalyExclusionRequest;
use App\Models\AnomalyExclusion;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Periods of a twin's history its editors say weren't normal.
 */
final readonly class TwinAnomalyExclusionController
{
    public function store(StoreAnomalyExclusionRequest $request, Twin $twin, #[CurrentUser] User $user, CreateAnomalyExclusion $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $note = $request->string('note')->trim()->value();
        $action->handle($twin, $user, $request->startsAt(), $request->endsAt(), $note === '' ? null : $note);

        return back();
    }

    public function destroy(Twin $twin, AnomalyExclusion $anomalyExclusion, DeleteAnomalyExclusion $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($anomalyExclusion);

        return back();
    }
}
