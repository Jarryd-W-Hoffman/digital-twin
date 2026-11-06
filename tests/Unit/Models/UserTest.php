<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\User;

test('to array', function (): void {
    $user = User::factory()->create()->refresh();

    expect(array_keys($user->toArray()))
        ->toBe([
            'id',
            'name',
            'email',
            'email_verified_at',
            'two_factor_confirmed_at',
            'created_at',
            'updated_at',
            'current_organisation_id',
        ]);
});

it('works in its current organisation', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);

    expect($user->organisation()->is($organisation))->toBeTrue()
        ->and($user->currentOrganisation?->is($organisation))->toBeTrue()
        ->and($user->memberships)->toHaveCount(1)
        ->and($user->roleIn($organisation))->toBe(OrganisationRole::Viewer);
});
