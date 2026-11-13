<?php

declare(strict_types=1);

use App\Actions\CreateIssue;
use App\Models\Issue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

$at = ['lon' => 174.76, 'lat' => -36.85, 'height' => 14.0];
$view = ['lon' => 174.761, 'lat' => -36.851, 'height' => 80.0, 'heading' => 15.0, 'pitch' => -40.0, 'roll' => 0.0];

it('pins an issue numbered after the last, with its first comment and photo', function () use ($at, $view): void {
    Storage::fake('local');
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    Issue::factory()->for($twin)->create();

    $issue = resolve(CreateIssue::class)->handle($twin, $user, 'Seal torn on freezer 2', $at, $view, 'Door seal is split.', UploadedFile::fake()->image('seal.jpg'));

    $comment = $issue->comments()->sole();
    expect($issue->number)->toBe(2)
        ->and($issue->title)->toBe('Seal torn on freezer 2')
        ->and([$issue->lon, $issue->lat, $issue->height])->toBe([174.76, -36.85, 14.0])
        ->and($issue->fresh()?->view)->toEqual($view)
        ->and($issue->author_id)->toBe($user->id)
        ->and($comment->body)->toBe('Door seal is split.')
        ->and($comment->author_id)->toBe($user->id)
        ->and($comment->photo)->toStartWith($issue->storagePrefix().'/')->toEndWith('.jpg');
    Storage::disk('local')->assertExists((string) $comment->photo);
});

it('raises an issue without a comment when nothing more was said', function () use ($at, $view): void {
    [$user, $organisation] = member();

    $issue = resolve(CreateIssue::class)->handle(twinIn($organisation), $user, 'Check this', $at, $view);

    expect($issue->number)->toBe(1)
        ->and($issue->comments()->count())->toBe(0);
});
