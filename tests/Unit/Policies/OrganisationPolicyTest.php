<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\User;
use App\Policies\OrganisationPolicy;

it('lets members see and managers manage', function (OrganisationRole $role, bool $manage): void {
    [$user, $organisation] = member($role);

    expect(new OrganisationPolicy()->view($user, $organisation))->toBeTrue()
        ->and(new OrganisationPolicy()->manageMembers($user, $organisation))->toBe($manage);
})->with([
    [OrganisationRole::Owner, true],
    [OrganisationRole::Admin, true],
    [OrganisationRole::Operator, false],
    [OrganisationRole::Viewer, false],
]);

it('gives outsiders nothing', function (): void {
    [, $organisation] = member();
    $outsider = User::factory()->create();

    expect(new OrganisationPolicy()->view($outsider, $organisation))->toBeFalse()
        ->and(new OrganisationPolicy()->manageMembers($outsider, $organisation))->toBeFalse();
});
