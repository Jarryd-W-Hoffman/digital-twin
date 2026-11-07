<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AbortDirectUpload;
use App\Actions\StartDirectUpload;
use App\Enums\ProjectAssetStatus;
use App\Http\Requests\StartDirectUploadRequest;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Support\DirectUploads;
use App\Support\PointCloudOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The browser's side of a direct upload: start it or give up on it. The bytes themselves go straight to the bucket.
 */
final readonly class ProjectUploadController
{
    public function store(StartDirectUploadRequest $request, Project $project, StartDirectUpload $action): JsonResponse
    {
        Gate::authorize('update', $project);
        abort_unless(DirectUploads::enabled(), 404);

        $started = $action->handle(
            $project,
            $request->string('name')->value(),
            $request->string('filename')->value(),
            $request->integer('bytes'),
            PointCloudOptions::from($request),
        );

        return response()->json([
            'id' => $started['asset']->id,
            'partBytes' => $started['partBytes'],
            'parts' => $started['parts'],
        ], 201);
    }

    public function destroy(Request $request, Project $project, ProjectAsset $asset, AbortDirectUpload $action): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($asset->status === ProjectAssetStatus::Uploading, 409);

        $action->handle($asset);

        return $request->wantsJson() ? response()->json(status: 204) : back();
    }
}
