<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;
use App\Policies\IssueCommentPolicy;
use App\Policies\IssuePolicy;

it("lets operators close anyone's issue and editors delete it", function (OrganisationRole $role, bool $close, bool $delete): void {
    [$user, $organisation] = member($role);
    $issue = Issue::factory()->for(twinIn($organisation))->create();
    $comment = IssueComment::factory()->for($issue)->create();

    expect(new IssuePolicy()->close($user, $issue))->toBe($close)
        ->and(new IssuePolicy()->delete($user, $issue))->toBe($delete)
        ->and(new IssueCommentPolicy()->delete($user, $comment))->toBe($delete);
})->with([
    [OrganisationRole::Owner, true, true],
    [OrganisationRole::Admin, true, true],
    [OrganisationRole::Operator, true, false],
    [OrganisationRole::Viewer, false, false],
]);

it('lets the author close and delete their own issue and comments', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $issue = Issue::factory()->for(twinIn($organisation))->create(['author_id' => $user->id]);
    $comment = IssueComment::factory()->for($issue)->create(['author_id' => $user->id]);

    expect(new IssuePolicy()->close($user, $issue))->toBeTrue()
        ->and(new IssuePolicy()->delete($user, $issue))->toBeTrue()
        ->and(new IssueCommentPolicy()->delete($user, $comment))->toBeTrue();
});

it('gives outsiders nothing', function (): void {
    [, $organisation] = member();
    $issue = Issue::factory()->for(twinIn($organisation))->create();
    $comment = IssueComment::factory()->for($issue)->create();
    $outsider = User::factory()->create();

    expect(new IssuePolicy()->close($outsider, $issue))->toBeFalse()
        ->and(new IssuePolicy()->delete($outsider, $issue))->toBeFalse()
        ->and(new IssueCommentPolicy()->delete($outsider, $comment))->toBeFalse();
});
