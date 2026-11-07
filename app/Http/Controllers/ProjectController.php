<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateProject;
use App\Actions\DeleteProject;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Device;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\Twin;
use App\Models\User;
use App\Support\DirectUploads;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ProjectController
{
    public function index(#[CurrentUser] User $user): Response
    {
        $organisation = $user->organisation();

        return Inertia::render('projects/index', [
            'projects' => $organisation->projects()
                ->withCount(['twins', 'assets'])
                ->latest('updated_at')
                ->get()
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'description' => $project->description,
                    'twins' => $project->twins_count,
                    'assets' => $project->assets_count,
                    'updatedAt' => $project->updated_at->toIso8601String(),
                ]),
            'canCreate' => Gate::allows('create', [Project::class, $organisation]),
        ]);
    }

    public function create(#[CurrentUser] User $user): Response
    {
        Gate::authorize('create', [Project::class, $user->organisation()]);

        return Inertia::render('projects/create');
    }

    public function store(StoreProjectRequest $request, #[CurrentUser] User $user, CreateProject $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('create', [Project::class, $organisation]);

        $project = $action->handle(
            $organisation,
            $request->string('name')->value(),
            $request->filled('description') ? $request->string('description')->value() : null,
        );

        return to_route('projects.show', $project);
    }

    public function show(Project $project, #[CurrentUser] User $user): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
            ],
            'twins' => $project->twins()
                ->with('latestVersion:id,twin_id,number,created_at')
                ->latest('updated_at')
                ->get()
                ->map(fn (Twin $twin): array => [
                    'id' => $twin->id,
                    'name' => $twin->name,
                    'description' => $twin->description,
                    'version' => $twin->latestVersion?->number,
                    'updatedAt' => $twin->updated_at->toIso8601String(),
                ]),
            'assets' => $project->assets()->latest()->get()->map(fn (ProjectAsset $asset): array => [
                'id' => $asset->id,
                'name' => $asset->name,
                'kind' => $asset->kind->value,
                'originalName' => $asset->original_name,
                'bytes' => $asset->bytes,
                'files' => $asset->files,
                'status' => $asset->status->value,
                'error' => $asset->error,
                'progress' => $asset->progress,
                'log' => $asset->log,
                'crs' => $asset->crs,
                'createdAt' => $asset->created_at->toIso8601String(),
            ]),
            'devices' => $project->devices()->orderBy('name')->get()->map(fn (Device $device): array => [
                'id' => $device->id,
                'name' => $device->name,
                'username' => $device->mqttUsername(),
                'lastSeenAt' => $device->last_seen_at?->toIso8601String(),
            ]),
            'mqtt' => [
                'brokerUrl' => config()->string('meridian.mqtt.broker_url'),
                'topicPrefix' => $project->topicPrefix(),
            ],
            'directUploads' => DirectUploads::enabled(),
            'can' => [
                'update' => $user->can('update', $project),
            ],
        ]);
    }

    public function destroy(Project $project, DeleteProject $action): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $action->handle($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted.')]);

        return to_route('projects.index');
    }
}
