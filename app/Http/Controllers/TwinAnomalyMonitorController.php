<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteAnomalyMonitor;
use App\Actions\SaveAnomalyMonitor;
use App\Http\Requests\SaveAnomalyMonitorRequest;
use App\Models\AnomalyMonitor;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The metrics of a twin its editors have watched for anomalies.
 */
final readonly class TwinAnomalyMonitorController
{
    public function store(SaveAnomalyMonitorRequest $request, Twin $twin, #[CurrentUser] User $user, SaveAnomalyMonitor $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($twin, $user, $request->string('asset')->value(), $request->string('metric')->value(), $request->settings());

        return back();
    }

    public function update(SaveAnomalyMonitorRequest $request, Twin $twin, AnomalyMonitor $anomalyMonitor, #[CurrentUser] User $user, SaveAnomalyMonitor $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($twin, $user, $anomalyMonitor->asset, $anomalyMonitor->metric, $request->settings());

        return back();
    }

    public function destroy(Twin $twin, AnomalyMonitor $anomalyMonitor, DeleteAnomalyMonitor $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($anomalyMonitor);

        return back();
    }
}
