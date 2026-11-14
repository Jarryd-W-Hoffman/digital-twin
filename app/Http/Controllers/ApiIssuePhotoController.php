<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Twin;
use App\Support\IssuePhoto;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A comment's photo for API tokens, served as an image only.
 */
final readonly class ApiIssuePhotoController
{
    public function show(Request $request, Twin $twin, Issue $issue, IssueComment $comment): StreamedResponse
    {
        abort_unless(AuthenticateApiToken::token($request)->reaches($twin), 404);

        return IssuePhoto::response($comment);
    }
}
