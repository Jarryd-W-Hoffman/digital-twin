<?php

declare(strict_types=1);

use App\Actions\CloseIssue;
use App\Actions\ReopenIssue;
use App\Models\Issue;
use App\Models\User;

it('closes an issue once, keeping who closed it first', function (): void {
    $issue = Issue::factory()->create();
    [$first, $second] = User::factory()->count(2)->create();

    resolve(CloseIssue::class)->handle($issue, $first);
    resolve(CloseIssue::class)->handle($issue, $second);

    expect($issue->fresh()?->closed_by)->toBe($first->id)
        ->and($issue->fresh()?->closed_at)->not->toBeNull();
});

it('opens a closed issue again', function (): void {
    $issue = Issue::factory()->closed()->create();

    resolve(ReopenIssue::class)->handle($issue);

    expect($issue->fresh()?->closed_at)->toBeNull()
        ->and($issue->fresh()?->closed_by)->toBeNull();
});
