<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ServeProjectAssetFile;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a file from a project library upload to members of the project's
 * organisation. Uploaded bytes are never rendered as a page: a fixed content
 * type per extension, nosniff, and a CSP that forbids everything.
 */
final readonly class ProjectAssetFileController
{
    public function show(ProjectAsset $asset, string $path, ServeProjectAssetFile $files): StreamedResponse
    {
        Gate::authorize('view', $asset->project);

        return $files->handle($asset, $path);
    }
}
