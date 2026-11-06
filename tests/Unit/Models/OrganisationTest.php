<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\Organisation;

test('to array', function (): void {
    expect(array_keys(Organisation::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'name', 'slug', 'created_at', 'updated_at']);
});

it('knows its members and twins', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    twinIn($organisation);

    expect($organisation->memberships)->toHaveCount(1)
        ->and($organisation->twins)->toHaveCount(1)
        ->and($organisation->roleOf($user))->toBe(OrganisationRole::Operator);
});
