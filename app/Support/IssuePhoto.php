<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\IssueComment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A comment's photo as an image only: a fixed content type, nosniff, and a
 * CSP that forbids everything else, so a crafted upload can't run as a page.
 */
final readonly class IssuePhoto
{
    private const array TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public static function response(IssueComment $comment): StreamedResponse
    {
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
