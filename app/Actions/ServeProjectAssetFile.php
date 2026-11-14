<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectAsset;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ServeProjectAssetFile
{
    private const array TYPES = [
        'json' => 'application/json',
        'geojson' => 'application/geo+json',
        'glb' => 'model/gltf-binary',
        'gltf' => 'model/gltf+json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ktx2' => 'image/ktx2',
        'terrain' => 'application/vnd.quantized-mesh',
    ];

    /**
     * Streams one file of an upload (a model, or a tile of a tileset or
     * terrain), never one outside its directory.
     */
    public function handle(ProjectAsset $asset, string $path): StreamedResponse
    {
        abort_unless(array_all(explode('/', $path), fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true)), 404);

        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $file = $asset->directory.'/'.$path;

        abort_unless($disk->fileExists($file), 404);

        return $disk->response($file, null, [
            'Content-Type' => self::TYPES[mb_strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
