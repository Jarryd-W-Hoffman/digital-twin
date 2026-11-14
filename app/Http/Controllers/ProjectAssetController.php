<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteProjectAsset;
use App\Actions\StoreProjectAsset;
use App\Enums\ProjectAssetKind;
use App\Http\Requests\StoreProjectAssetRequest;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\User;
use App\Support\PointCloudOptions;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The project's library: uploads its twins can show as layers.
 */
final readonly class ProjectAssetController
{
    public function store(StoreProjectAssetRequest $request, Project $project, StoreProjectAsset $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $file = $request->file('file');
        assert($file instanceof UploadedFile);

        $asset = $action->handle($project, $file, $request->string('name')->value(), PointCloudOptions::from($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $asset->isReady() => __(':name uploaded.', ['name' => $asset->name]),
            $asset->kind === ProjectAssetKind::Terrain => __(':name uploaded. Converting it to terrain…', ['name' => $asset->name]),
            $asset->kind === ProjectAssetKind::Bim => __(':name uploaded. Converting it to a model…', ['name' => $asset->name]),
            $asset->kind === ProjectAssetKind::Vector => __(':name uploaded. Converting it to a map layer…', ['name' => $asset->name]),
            $asset->kind === ProjectAssetKind::Imagery => __(':name uploaded. Tiling the image…', ['name' => $asset->name]),
            default => __(':name uploaded. Converting it to 3D Tiles…', ['name' => $asset->name]),
        }]);

        return to_route('projects.show', $project);
    }

    public function destroy(Project $project, ProjectAsset $asset, #[CurrentUser] User $user, DeleteProjectAsset $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $action->handle($asset, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $asset->name])]);

        return to_route('projects.show', $project);
    }
}
