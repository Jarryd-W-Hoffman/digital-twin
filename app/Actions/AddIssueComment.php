<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Adds a comment to an issue, keeping its photo with the issue's files.
 */
final readonly class AddIssueComment
{
    public function handle(Issue $issue, User $author, ?string $body, ?UploadedFile $photo = null): IssueComment
    {
        $path = null;

        if ($photo instanceof UploadedFile) {
            $path = Storage::disk(config()->string('meridian.assets_disk'))
                ->putFileAs($issue->storagePrefix(), $photo, Str::uuid7().'.'.$photo->extension());
        }

        $comment = $issue->comments()->create([
            'author_id' => $author->id,
            'body' => $body,
            'photo' => $path ?: null,
        ]);

        $issue->touch();

        return $comment;
    }
}
