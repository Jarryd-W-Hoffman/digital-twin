<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddTypeToTwin;
use App\Actions\SaveTypeToLibrary;
use App\Http\Requests\StoreTwinTypeRequest;
use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Moving custom types between a twin and the organisation's library. Both
 * directions copy, so a twin never changes when the library does.
 */
final readonly class TwinTypeController
{
    public function store(StoreTwinTypeRequest $request, Twin $twin, #[CurrentUser] User $user, AddTypeToTwin $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $type = $request->assetType();
        $version = $action->handle($twin, $user, $type);

        Inertia::flash('toast', ['type' => 'success', 'message' => $version instanceof TwinVersion
            ? __(':name added from the library.', ['name' => $type->name])
            : __('This twin already has :name.', ['name' => $type->name])]);

        return to_route('twins.show', $twin);
    }

    /**
     * Saves one of the twin's types to the library, replacing the library's
     * type with the same id.
     */
    public function update(Twin $twin, string $type, #[CurrentUser] User $user, SaveTypeToLibrary $action): RedirectResponse
    {
        Gate::authorize('update', $twin);
        Gate::authorize('manageTypes', $twin->organisation);

        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $found = array_find(is_array($definition['types'] ?? null) ? $definition['types'] : [], fn (mixed $candidate): bool => is_array($candidate) && ($candidate['id'] ?? null) === $type);
        abort_unless(is_array($found), 404);

        /** @var array<string, mixed> $found */
        $saved = $action->handle($twin->organisation, $user, $found);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved to the library.', ['name' => $saved->name])]);

        return to_route('twins.show', $twin);
    }
}
