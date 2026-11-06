<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\Membership;

test('to array', function (): void {
    expect(array_keys(Membership::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'organisation_id', 'user_id', 'role', 'created_at', 'updated_at']);
});

it('belongs to a user and an organisation', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $membership = $organisation->memberships()->sole();

    expect($membership->user->is($user))->toBeTrue()
        ->and($membership->organisation->is($organisation))->toBeTrue()
        ->and($membership->role)->toBe(OrganisationRole::Admin);
});
