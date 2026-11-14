<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteSurveyComparison;
use App\Actions\SaveSurveyComparison;
use App\Http\Requests\SaveSurveyComparisonRequest;
use App\Models\SurveyComparison;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Areas of a twin where its editors compare surveys of the ground.
 */
final readonly class TwinSurveyComparisonController
{
    public function store(SaveSurveyComparisonRequest $request, Twin $twin, #[CurrentUser] User $user, SaveSurveyComparison $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($twin, $user, null, $request->string('name')->value(), $request->area(), $request->surveys());

        return back();
    }

    public function update(SaveSurveyComparisonRequest $request, Twin $twin, SurveyComparison $surveyComparison, #[CurrentUser] User $user, SaveSurveyComparison $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($twin, $user, $surveyComparison, $request->string('name')->value(), $request->area(), $request->surveys());

        return back();
    }

    public function destroy(Twin $twin, SurveyComparison $surveyComparison, DeleteSurveyComparison $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($surveyComparison);

        return back();
    }
}
