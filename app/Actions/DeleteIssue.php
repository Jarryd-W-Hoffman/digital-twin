<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes an issue with its comments, then their photos.
 */
final readonly class DeleteIssue
{
    public function handle(Issue $issue): void
    {
        $directory = $issue->storagePrefix();

        $issue->delete();

        Storage::disk(config()->string('meridian.assets_disk'))->deleteDirectory($directory);
    }
}
