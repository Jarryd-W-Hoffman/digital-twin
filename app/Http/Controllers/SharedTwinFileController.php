<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ServeProjectAssetFile;
use App\Http\Middleware\ResolveShareLink;
use App\Models\ProjectAsset;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file of an upload the shared twin shows. Nothing else in the project's
 * library can be loaded through the link.
 */
final readonly class SharedTwinFileController
{
    public function show(Request $request, string $token, string $asset, string $path, ServeProjectAssetFile $files): StreamedResponse
    {
        $link = ResolveShareLink::link($request);
        abort_unless(in_array($asset, $link->assetIds(), true), 404);

        $upload = ProjectAsset::query()->whereKey($asset)->where('project_id', $link->twin->project_id)->first();
        abort_unless($upload instanceof ProjectAsset, 404);

        return $files->handle($upload, $path);
    }
}
