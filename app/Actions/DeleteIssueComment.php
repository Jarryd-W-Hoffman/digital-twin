<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\IssueComment;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a comment and its photo.
 */
final readonly class DeleteIssueComment
{
    public function handle(IssueComment $comment): void
    {
        $comment->delete();

        if ($comment->photo !== null) {
            Storage::disk(config()->string('meridian.assets_disk'))->delete($comment->photo);
        }
    }
}
