<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;

it('belongs to a twin, its author and who closed it, with its comments', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->closed()->create();
    $comment = IssueComment::factory()->for($issue)->create();

    expect($issue->twin->is($twin))->toBeTrue()
        ->and($issue->author)->toBeInstanceOf(User::class)
        ->and($issue->closer)->toBeInstanceOf(User::class)
        ->and($issue->comments->sole()->is($comment))->toBeTrue()
        ->and($comment->issue->is($issue))->toBeTrue()
        ->and($comment->author)->toBeInstanceOf(User::class)
        ->and($twin->issues->sole()->is($issue))->toBeTrue()
        ->and($issue->storagePrefix())->toBe('projects/'.$twin->project_id.'/twins/'.$twin->id.'/issues/'.$issue->id);
});

it('numbers issues per twin', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);

    expect(Issue::factory()->for($twin)->create()->number)->toBe(1)
        ->and(Issue::factory()->for($twin)->create()->number)->toBe(2)
        ->and(Issue::factory()->create()->number)->toBe(1);
});

it('keeps an issue when its author leaves', function (): void {
    $issue = Issue::factory()->create();
    $comment = IssueComment::factory()->for($issue)->create(['author_id' => $issue->author_id]);

    $issue->author?->delete();

    expect($issue->fresh()?->author_id)->toBeNull()
        ->and($comment->fresh()?->author_id)->toBeNull();
});
