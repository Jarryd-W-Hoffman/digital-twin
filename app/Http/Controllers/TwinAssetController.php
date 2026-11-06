<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddAssetToTwin;
use App\Actions\StoreProjectAsset;
use App\Http\Requests\StoreProjectAssetRequest;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Uploading from a twin: the file goes into the project's library and is
 * added to this twin straight away.
 */
final readonly class TwinAssetController
{
    public function store(StoreProjectAssetRequest $request, Twin $twin, #[CurrentUser] User $user, StoreProjectAsset $store, AddAssetToTwin $add): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $file = $request->file('file');
        assert($file instanceof UploadedFile);

        $asset = $store->handle($twin->project, $file, $request->string('name')->value());
        $add->handle($twin, $user, $asset);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name uploaded.', ['name' => $asset->name])]);

        return to_route('twins.show', $twin);
    }
}
