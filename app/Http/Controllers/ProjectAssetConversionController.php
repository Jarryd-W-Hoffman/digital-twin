<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RetryPointCloudConversion;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Http\Requests\RetryPointCloudConversionRequest;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Support\PointCloudOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Retrying a point cloud whose conversion failed.
 */
final readonly class ProjectAssetConversionController
{
    public function store(RetryPointCloudConversionRequest $request, Project $project, ProjectAsset $asset, RetryPointCloudConversion $action): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($asset->kind === ProjectAssetKind::PointCloud && $asset->status === ProjectAssetStatus::Failed, 409);

        $action->handle($asset, PointCloudOptions::from($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Converting :name again.', ['name' => $asset->name])]);

        return to_route('projects.show', $project);
    }
}
