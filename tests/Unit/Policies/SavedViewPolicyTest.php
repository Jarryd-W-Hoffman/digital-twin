<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\SavedView;
use App\Models\User;
use App\Policies\SavedViewPolicy;

it("lets editors look after anyone's shared views, and share them", function (OrganisationRole $role, bool $edits): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    $shared = SavedView::factory()->for($twin)->shared()->create();
    $private = SavedView::factory()->for($twin)->create();

    expect(new SavedViewPolicy()->update($user, $shared))->toBe($edits)
        ->and(new SavedViewPolicy()->delete($user, $shared))->toBe($edits)
        ->and(new SavedViewPolicy()->share($user, $shared))->toBe($edits)
        ->and(new SavedViewPolicy()->update($user, $private))->toBeFalse()
        ->and(new SavedViewPolicy()->delete($user, $private))->toBe($edits);
})->with([
    [OrganisationRole::Owner, true],
    [OrganisationRole::Admin, true],
    [OrganisationRole::Operator, false],
    [OrganisationRole::Viewer, false],
]);

it('lets the author change and delete their own view, but not share it', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $view = SavedView::factory()->for(twinIn($organisation))->create(['author_id' => $user->id]);

    expect(new SavedViewPolicy()->update($user, $view))->toBeTrue()
        ->and(new SavedViewPolicy()->delete($user, $view))->toBeTrue()
        ->and(new SavedViewPolicy()->share($user, $view))->toBeFalse();
});

it('gives outsiders nothing', function (): void {
    [, $organisation] = member();
    $view = SavedView::factory()->for(twinIn($organisation))->shared()->create();
    $outsider = User::factory()->create();

    expect(new SavedViewPolicy()->update($outsider, $view))->toBeFalse()
        ->and(new SavedViewPolicy()->delete($outsider, $view))->toBeFalse()
        ->and(new SavedViewPolicy()->share($outsider, $view))->toBeFalse();
});
