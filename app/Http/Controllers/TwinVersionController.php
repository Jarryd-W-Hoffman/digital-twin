<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveTwinVersion;
use App\Http\Requests\StoreTwinVersionRequest;
use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final readonly class TwinVersionController
{
    public function store(StoreTwinVersionRequest $request, Twin $twin, #[CurrentUser] User $user, SaveTwinVersion $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $version = $action->handle($twin, $user, $request->definition(), $request->filled('note') ? $request->string('note')->value() : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved version :number.', ['number' => $version->number])]);

        return to_route('twins.show', $twin);
    }

    /**
     * The raw definition of one version, for download or for the gateway.
     */
    public function show(Twin $twin, TwinVersion $version): JsonResponse
    {
        Gate::authorize('view', $twin);

        return response()->json($version->definition, headers: [
            'Content-Disposition' => sprintf('attachment; filename="%s-v%d.json"', str($twin->name)->slug(), $version->number),
        ]);
    }
}
