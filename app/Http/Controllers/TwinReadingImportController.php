<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteReadingImport;
use App\Actions\StoreReadingImport;
use App\Http\Requests\StoreReadingImportRequest;
use App\Models\ReadingImport;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * CSV files of readings a twin's editors import into its history.
 */
final readonly class TwinReadingImportController
{
    public function store(StoreReadingImportRequest $request, Twin $twin, #[CurrentUser] User $user, StoreReadingImport $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($twin, $user, $request->upload(), $request->settings(), $request->series());

        return back();
    }

    public function destroy(Twin $twin, ReadingImport $readingImport, DeleteReadingImport $action): RedirectResponse
    {
        Gate::authorize('update', $twin);

        $action->handle($readingImport);

        return back();
    }
}
