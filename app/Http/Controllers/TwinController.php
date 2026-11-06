<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateTwin;
use App\Actions\DeleteTwin;
use App\Http\Requests\StoreTwinRequest;
use App\Http\Requests\UpdateTwinRequest;
use App\Models\Twin;
use App\Models\TwinAsset;
use App\Models\TwinVersion;
use App\Models\User;
use App\Support\SampleTwins;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
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
                ->with('latestVersion:id,twin_id,number,created_at')
                ->withCount('assets')
                ->latest('updated_at')
                ->get()
                ->map(fn (Twin $twin): array => [
                    'id' => $twin->id,
                    'name' => $twin->name,
                    'description' => $twin->description,
                    'version' => $twin->latestVersion?->number,
                    'assets' => $twin->assets_count,
                    'updatedAt' => $twin->updated_at->toIso8601String(),
                ]),
            'canCreate' => Gate::allows('create', [Twin::class, $organisation]),
        ]);
    }

    public function create(#[CurrentUser] User $user, SampleTwins $samples): Response
    {
        Gate::authorize('create', [Twin::class, $user->organisation()]);

        return Inertia::render('twins/create', [
            'samples' => collect($samples->all())->map(fn (string $name, string $id): array => ['id' => $id, 'name' => $name])->values(),
        ]);
    }

    public function store(StoreTwinRequest $request, #[CurrentUser] User $user, CreateTwin $action): RedirectResponse
    {
        $organisation = $user->organisation();
        Gate::authorize('create', [Twin::class, $organisation]);

        $twin = $action->handle(
            $organisation,
            $user,
            $request->string('name')->value(),
            $request->filled('description') ? $request->string('description')->value() : null,
            $request->definition(),
        );

        return to_route('twins.show', $twin);
    }

    public function show(Twin $twin, #[CurrentUser] User $user): Response
    {
        Gate::authorize('view', $twin);

        $latest = $twin->latestVersion()->firstOrFail();

        return Inertia::render('twins/show', [
            'twin' => [
                'id' => $twin->id,
                'name' => $twin->name,
                'description' => $twin->description,
            ],
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
            'assets' => $twin->assets()->latest()->get()->map(fn (TwinAsset $asset): array => [
                'id' => $asset->id,
                'kind' => $asset->kind->value,
                'name' => $asset->name,
                'originalName' => $asset->original_name,
                'bytes' => $asset->bytes,
                'files' => $asset->files,
                'url' => $asset->url(),
            ]),
            'can' => [
                'update' => $user->can('update', $twin),
                'operate' => $user->can('operate', $twin),
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

        return to_route('twins.index');
    }
}
