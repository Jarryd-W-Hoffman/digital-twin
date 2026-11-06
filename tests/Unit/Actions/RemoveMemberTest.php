<?php

declare(strict_types=1);

use App\Actions\RemoveMember;
use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('removes a member and moves them to another of their organisations', function (): void {
    [, $organisation] = member();
    $colleague = User::factory()->create(['current_organisation_id' => $organisation->id]);
    $membership = $organisation->memberships()->create(['user_id' => $colleague->id, 'role' => OrganisationRole::Operator]);
    $elsewhere = Organisation::factory()->create();
    $elsewhere->memberships()->create(['user_id' => $colleague->id, 'role' => OrganisationRole::Owner]);

    resolve(RemoveMember::class)->handle($membership);

    expect($colleague->roleIn($organisation))->toBeNull()
        ->and($colleague->refresh()->current_organisation_id)->toBe($elsewhere->id);
});

it('leaves the current organisation alone when it was another one', function (): void {
    [, $organisation] = member();
    [$colleague, $theirs] = member();
    $membership = $organisation->memberships()->create(['user_id' => $colleague->id, 'role' => OrganisationRole::Viewer]);

    resolve(RemoveMember::class)->handle($membership);

    expect($colleague->refresh()->current_organisation_id)->toBe($theirs->id);
});

it('removes a co-owner but never the last owner', function (): void {
    [, $organisation] = member();
    $second = $organisation->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => OrganisationRole::Owner]);

    resolve(RemoveMember::class)->handle($second);

    expect(fn () => resolve(RemoveMember::class)->handle($organisation->memberships()->sole()))->toThrow(ValidationException::class);
});
