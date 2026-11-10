<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AllowOpcUaWrites;
use App\Http\Requests\UpdateTwinOpcUaWritesRequest;
use App\Models\Twin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Whether operators' setpoints are written to the twin's OPC UA servers.
 */
final readonly class TwinOpcUaWritesController
{
    public function update(UpdateTwinOpcUaWritesRequest $request, Twin $twin, AllowOpcUaWrites $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $allowed = $request->boolean('allowed');
        $action->handle($twin, $allowed);

        Inertia::flash('toast', ['type' => 'success', 'message' => $allowed
            ? __('Operators’ setpoints now go to the OPC UA servers.')
            : __('Writing to OPC UA is off.')]);

        return to_route('twins.show', $twin);
    }
}
