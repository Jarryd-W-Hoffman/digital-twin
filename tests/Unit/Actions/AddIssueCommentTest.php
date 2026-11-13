<?php

declare(strict_types=1);

use App\Actions\AddIssueComment;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('adds a comment and brings the issue up the list', function (): void {
    $this->freezeSecond();
    $issue = Issue::factory()->create(['updated_at' => now()->subDay()]);
    $user = User::factory()->create();

    $comment = resolve(AddIssueComment::class)->handle($issue, $user, 'Fixed the seal.');

    expect($comment->body)->toBe('Fixed the seal.')
        ->and($comment->photo)->toBeNull()
        ->and($comment->author_id)->toBe($user->id)
        ->and($issue->fresh()?->updated_at?->equalTo(now()))->toBeTrue();
});

it('keeps a photo with the issue', function (): void {
    Storage::fake('local');
    $issue = Issue::factory()->create();

    $comment = resolve(AddIssueComment::class)->handle($issue, User::factory()->create(), null, UploadedFile::fake()->image('after.png'));

    expect($comment->body)->toBeNull()
        ->and($comment->photo)->toStartWith($issue->storagePrefix().'/')->toEndWith('.png');
    Storage::disk('local')->assertExists((string) $comment->photo);
});
