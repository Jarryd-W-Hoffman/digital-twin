<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SignDirectUploadParts;
use App\Enums\ProjectAssetStatus;
use App\Http\Requests\SignDirectUploadPartsRequest;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Presigned URLs the browser PUTs a direct upload's parts to.
 */
final readonly class ProjectUploadPartController
{
    public function store(SignDirectUploadPartsRequest $request, Project $project, ProjectAsset $asset, SignDirectUploadParts $action): JsonResponse
    {
        Gate::authorize('update', $project);
        abort_unless($asset->status === ProjectAssetStatus::Uploading, 409);

        return response()->json(['urls' => $action->handle($asset, $request->parts())]);
    }
}
