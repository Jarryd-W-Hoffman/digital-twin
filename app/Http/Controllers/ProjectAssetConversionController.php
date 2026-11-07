<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RetryConversion;
use App\Enums\ProjectAssetStatus;
use App\Http\Requests\RetryConversionRequest;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Support\PointCloudOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Retrying a point cloud or DEM whose conversion failed.
 */
final readonly class ProjectAssetConversionController
{
    public function store(RetryConversionRequest $request, Project $project, ProjectAsset $asset, RetryConversion $action): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($asset->kind->isConverted() && $asset->status === ProjectAssetStatus::Failed, 409);

        $action->handle($asset, PointCloudOptions::from($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Converting :name again.', ['name' => $asset->name])]);

        return to_route('projects.show', $project);
    }
}
