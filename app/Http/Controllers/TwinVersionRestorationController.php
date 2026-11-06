<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveTwinVersion;
use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final readonly class TwinVersionRestorationController
{
    /**
     * Restoring saves the old definition as a new version, so history only grows.
     */
    public function store(Twin $twin, TwinVersion $version, #[CurrentUser] User $user, SaveTwinVersion $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $restored = $action->handle($twin, $user, $version->definition, __('Restored version :number', ['number' => $version->number]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved version :number.', ['number' => $restored->number])]);

        return to_route('twins.show', $twin);
    }
}
