<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Twin;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a comment's photo to those who can see the twin, as an image only:
 * a fixed content type, nosniff, and a CSP that forbids everything else.
 */
final readonly class TwinIssuePhotoController
{
    private const array TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function show(Twin $twin, Issue $issue, IssueComment $comment): StreamedResponse
    {
        Gate::authorize('view', $twin);

        $disk = Storage::disk(config()->string('meridian.assets_disk'));

        abort_if($comment->photo === null || ! $disk->fileExists($comment->photo), 404);

        return $disk->response($comment->photo, null, [
            'Content-Type' => self::TYPES[mb_strtolower(pathinfo($comment->photo, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
