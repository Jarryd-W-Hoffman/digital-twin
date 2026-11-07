<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

it('follows the member role', function (OrganisationRole $role, bool $edit): void {
    [$user, $organisation] = member($role);
    $project = Project::factory()->for($organisation)->create();
    $policy = new ProjectPolicy();

    expect($policy->view($user, $project))->toBeTrue()
        ->and($policy->create($user, $organisation))->toBe($edit)
        ->and($policy->update($user, $project))->toBe($edit)
        ->and($policy->delete($user, $project))->toBe($edit);
})->with([
    [OrganisationRole::Owner, true],
    [OrganisationRole::Admin, true],
    [OrganisationRole::Operator, false],
    [OrganisationRole::Viewer, false],
]);

it('gives outsiders nothing', function (): void {
    [, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $outsider = User::factory()->create();
    $policy = new ProjectPolicy();

    expect($policy->view($outsider, $project))->toBeFalse()
        ->and($policy->create($outsider, $organisation))->toBeFalse()
        ->and($policy->update($outsider, $project))->toBeFalse()
        ->and($policy->delete($outsider, $project))->toBeFalse();
});
