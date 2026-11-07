<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CompleteDirectUpload;
use App\Enums\ProjectAssetStatus;
use App\Http\Requests\CompleteDirectUploadRequest;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Finishes a direct upload once all its parts are in the bucket.
 */
final readonly class ProjectUploadCompletionController
{
    public function store(CompleteDirectUploadRequest $request, Project $project, ProjectAsset $asset, CompleteDirectUpload $action): JsonResponse
    {
        Gate::authorize('update', $project);
        abort_unless($asset->status === ProjectAssetStatus::Uploading, 409);

        $action->handle($asset, $request->parts());

        return response()->json(['status' => 'processing']);
    }
}
