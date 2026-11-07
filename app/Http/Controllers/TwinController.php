<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateTwin;
use App\Actions\DeleteTwin;
use App\Actions\IssueGatewayTicket;
use App\Enums\ProjectAssetStatus;
use App\Http\Requests\StoreTwinRequest;
use App\Http\Requests\UpdateTwinRequest;
use App\Models\ProjectAsset;
use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;
use App\Support\SampleTwins;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final readonly class TwinController
{
    public function index(#[CurrentUser] User $user): Response
    {
        $organisation = $user->organisation();

        return Inertia::render('twins/index', [
            'twins' => $organisation->twins()
                ->with(['latestVersion:id,twin_id,number,created_at', 'project:id,name'])
                ->latest('updated_at')
                ->get()
                ->map(fn (Twin $twin): array => [
                    'id' => $twin->id,
                    'name' => $twin->name,
                    'description' => $twin->description,
                    'version' => $twin->latestVersion?->number,
                    'project' => ['id' => $twin->project->id, 'name' => $twin->project->name],
                    'updatedAt' => $twin->updated_at->toIso8601String(),
                ]),
            'canCreate' => Gate::allows('create', [Twin::class, $organisation]),
        ]);
    }

    public function create(Request $request, #[CurrentUser] User $user, SampleTwins $samples): Response
    {
        $organisation = $user->organisation();
        Gate::authorize('create', [Twin::class, $organisation]);

        return Inertia::render('twins/create', [
            'projects' => $organisation->projects()->orderBy('name')->get(['id', 'name']),
            'project' => $request->filled('project') ? $request->string('project')->value() : null,
            'samples' => collect($samples->all())->map(fn (string $name, string $id): array => ['id' => $id, 'name' => $name])->values(),
        ]);
    }

    public function store(StoreTwinRequest $request, #[CurrentUser] User $user, CreateTwin $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('create', [Twin::class, $organisation]);

        $twin = $action->handle(
            $request->project(),
            $user,
            $request->string('name')->value(),
            $request->filled('description') ? $request->string('description')->value() : null,
            $request->definition(),
        );

        return to_route('twins.show', $twin);
    }

    public function show(Twin $twin, #[CurrentUser] User $user, IssueGatewayTicket $tickets): Response
    {
        Gate::authorize('view', $twin);

        $latest = $twin->latestVersion()->firstOrFail();
        $canOperate = $user->can('operate', $twin);
        $online = $twin->gatewayOnline();

        return Inertia::render('twins/show', [
            'twin' => [
                'id' => $twin->id,
                'name' => $twin->name,
                'description' => $twin->description,
            ],
            'project' => ['id' => $twin->project->id, 'name' => $twin->project->name],
            'definition' => $latest->definition,
            'version' => $latest->number,
            'versions' => $twin->versions()->with('user:id,name')->latest('number')->limit(25)->get()
                ->map(fn (TwinVersion $version): array => [
                    'id' => $version->id,
                    'number' => $version->number,
                    'note' => $version->note,
                    'author' => $version->user?->name,
                    'createdAt' => $version->created_at->toIso8601String(),
                ]),
            // The project's library: what this twin shows, and what it could.
            'assets' => $twin->project->assets()->where('status', ProjectAssetStatus::Ready)->latest()->get()->map(fn (ProjectAsset $asset): array => [
                'id' => $asset->id,
                'kind' => $asset->kind->value,
                'name' => $asset->name,
                'originalName' => $asset->original_name,
                'bytes' => $asset->bytes,
                'files' => $asset->files,
                'url' => $asset->url(),
            ]),
            'gateway' => $twin->gateway_token_hash === null ? null : [
                'online' => $online,
                'url' => $online ? $twin->gateway_url : null,
                'seenAt' => $twin->gateway_seen_at?->toIso8601String(),
                'ticket' => $online ? $tickets->handle($twin, $user, $canOperate) : null,
            ],
            'can' => [
                'update' => $user->can('update', $twin),
                'operate' => $canOperate,
            ],
        ]);
    }

    public function update(UpdateTwinRequest $request, Twin $twin): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $twin->update([
            'name' => $request->string('name')->value(),
            'description' => $request->filled('description') ? $request->string('description')->value() : null,
        ]);

        return to_route('twins.show', $twin);
    }

    public function destroy(Twin $twin, DeleteTwin $action): RedirectResponse
    {
        Gate::authorize('delete', $twin);

        $action->handle($twin);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Twin deleted.')]);

        return to_route('projects.show', $twin->project_id);
    }
}
