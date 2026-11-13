<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The organisation's projects, for API tokens.
 */
final readonly class ApiProjectController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $token = AuthenticateApiToken::token($request);

        return ProjectResource::collection(Project::query()
            ->where('organisation_id', $token->organisation_id)
            ->withCount('twins')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(50));
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        abort_unless(AuthenticateApiToken::token($request)->reaches($project), 404);

        return new ProjectResource($project->loadCount('twins'));
    }
}
