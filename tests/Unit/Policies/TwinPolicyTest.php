<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\User;
use App\Policies\TwinPolicy;

it('follows the member role', function (OrganisationRole $role, bool $edit, bool $operate): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    $policy = new TwinPolicy();

    expect($policy->view($user, $twin))->toBeTrue()
        ->and($policy->create($user, $organisation))->toBe($edit)
        ->and($policy->update($user, $twin))->toBe($edit)
        ->and($policy->delete($user, $twin))->toBe($edit)
        ->and($policy->operate($user, $twin))->toBe($operate);
})->with([
    [OrganisationRole::Owner, true, true],
    [OrganisationRole::Admin, true, true],
    [OrganisationRole::Operator, false, true],
    [OrganisationRole::Viewer, false, false],
]);

it('gives outsiders nothing', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $outsider = User::factory()->create();
    $policy = new TwinPolicy();

    expect($policy->view($outsider, $twin))->toBeFalse()
        ->and($policy->create($outsider, $organisation))->toBeFalse()
        ->and($policy->update($outsider, $twin))->toBeFalse()
        ->and($policy->operate($outsider, $twin))->toBeFalse();
});
