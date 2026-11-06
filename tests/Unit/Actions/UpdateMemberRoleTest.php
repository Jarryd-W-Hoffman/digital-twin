<?php

declare(strict_types=1);

use App\Actions\UpdateMemberRole;
use App\Enums\OrganisationRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('changes a role', function (): void {
    [, $organisation] = member();
    $membership = $organisation->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => OrganisationRole::Viewer]);

    resolve(UpdateMemberRole::class)->handle($membership, OrganisationRole::Admin);

    expect($membership->refresh()->role)->toBe(OrganisationRole::Admin);
});

it('keeps the last owner an owner', function (): void {
    [, $organisation] = member();
    $owner = $organisation->memberships()->sole();

    expect(fn () => resolve(UpdateMemberRole::class)->handle($owner, OrganisationRole::Viewer))->toThrow(ValidationException::class)
        ->and($owner->refresh()->role)->toBe(OrganisationRole::Owner);
});
