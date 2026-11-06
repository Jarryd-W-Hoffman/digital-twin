<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Twin;
use App\Models\TwinAsset;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an uploaded model or tileset file to members of the twin's
 * organisation. Uploaded bytes are never rendered as a page: a fixed content
 * type per extension, nosniff, and a CSP that forbids everything.
 */
final readonly class TwinAssetFileController
{
    private const array TYPES = [
        'json' => 'application/json',
        'glb' => 'model/gltf-binary',
        'gltf' => 'model/gltf+json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ktx2' => 'image/ktx2',
    ];

    public function show(Twin $twin, TwinAsset $asset, string $path): StreamedResponse
    {
        Gate::authorize('view', $twin);

        abort_unless(array_all(explode('/', $path), fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true)), 404);

        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $file = $asset->directory().'/'.$path;

        abort_unless($disk->fileExists($file), 404);

        return $disk->response($file, null, [
            'Content-Type' => self::TYPES[mb_strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
