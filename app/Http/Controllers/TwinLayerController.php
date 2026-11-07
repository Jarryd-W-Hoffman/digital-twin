<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddAssetToTwin;
use App\Actions\RemoveAssetFromTwin;
use App\Http\Requests\StoreTwinLayerRequest;
use App\Models\ProjectAsset;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Showing a project library upload in a twin, or taking it out again. The
 * files stay in the library either way.
 */
final readonly class TwinLayerController
{
    public function store(StoreTwinLayerRequest $request, Twin $twin, #[CurrentUser] User $user, AddAssetToTwin $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $asset = $request->asset();
        $action->handle($twin, $user, $asset);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $asset->name])]);

        return to_route('twins.show', $twin);
    }

    public function destroy(Twin $twin, ProjectAsset $asset, #[CurrentUser] User $user, RemoveAssetFromTwin $action): RedirectResponse
    {
        Gate::authorize('update', $twin);
        abort_unless($asset->project_id === $twin->project_id, 404);

        $action->handle($twin, $user, $asset);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed from this twin.', ['name' => $asset->name])]);

        return to_route('twins.show', $twin);
    }
}
