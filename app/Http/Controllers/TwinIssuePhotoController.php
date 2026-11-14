<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Twin;
use App\Support\IssuePhoto;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a comment's photo to those who can see the twin, as an image only.
 */
final readonly class TwinIssuePhotoController
{
    public function show(Twin $twin, Issue $issue, IssueComment $comment): StreamedResponse
    {
        Gate::authorize('view', $twin);

        return IssuePhoto::response($comment);
    }
}
