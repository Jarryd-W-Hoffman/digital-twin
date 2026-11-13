<?php

declare(strict_types=1);

use App\Actions\DeleteIssue;
use App\Actions\DeleteIssueComment;
use App\Models\Issue;
use App\Models\IssueComment;
use Illuminate\Support\Facades\Storage;

it('deletes an issue with its comments and photos', function (): void {
    Storage::fake('local');
    $issue = Issue::factory()->create();
    $other = Issue::factory()->for($issue->twin)->create();
    $comment = IssueComment::factory()->for($issue)->create(['photo' => $issue->storagePrefix().'/a.jpg']);
    Storage::disk('local')->put((string) $comment->photo, 'x');
    Storage::disk('local')->put($other->storagePrefix().'/b.jpg', 'x');

    resolve(DeleteIssue::class)->handle($issue);

    expect($issue->fresh())->toBeNull()
        ->and($comment->fresh())->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([$other->storagePrefix().'/b.jpg']);
});

it('deletes a comment with its photo', function (): void {
    Storage::fake('local');
    $issue = Issue::factory()->create();
    $photo = IssueComment::factory()->for($issue)->create(['photo' => $issue->storagePrefix().'/a.jpg']);
    $words = IssueComment::factory()->for($issue)->create();
    Storage::disk('local')->put((string) $photo->photo, 'x');

    resolve(DeleteIssueComment::class)->handle($photo);
    resolve(DeleteIssueComment::class)->handle($words);

    expect($issue->comments()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
