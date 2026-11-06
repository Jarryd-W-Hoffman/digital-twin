<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteTwinAsset;
use App\Actions\StoreTwinAsset;
use App\Http\Requests\StoreTwinAssetRequest;
use App\Models\Twin;
use App\Models\TwinAsset;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final readonly class TwinAssetController
{
    public function store(StoreTwinAssetRequest $request, Twin $twin, #[CurrentUser] User $user, StoreTwinAsset $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $file = $request->file('file');
        assert($file instanceof UploadedFile);

        $asset = $action->handle($twin, $user, $file, $request->string('name')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name uploaded.', ['name' => $asset->name])]);

        return to_route('twins.show', $twin);
    }

    public function destroy(Twin $twin, TwinAsset $asset, #[CurrentUser] User $user, DeleteTwinAsset $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($asset, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed.', ['name' => $asset->name])]);

        return to_route('twins.show', $twin);
    }
}
