<?php

declare(strict_types=1);

use App\Actions\AddMember;
use App\Enums\OrganisationRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('adds an existing user with a role', function (): void {
    [, $organisation] = member();
    $colleague = User::factory()->create(['email' => 'ops@example.com']);

    $membership = resolve(AddMember::class)->handle($organisation, 'ops@example.com', OrganisationRole::Operator);

    expect($membership->user_id)->toBe($colleague->id)
        ->and($colleague->roleIn($organisation))->toBe(OrganisationRole::Operator);
});

it('needs an account to exist', function (): void {
    [, $organisation] = member();

    expect(fn () => resolve(AddMember::class)->handle($organisation, 'nobody@example.com', OrganisationRole::Viewer))
        ->toThrow(ValidationException::class, 'No one has an account with that email yet.');
});

it('does not add someone twice', function (): void {
    [$user, $organisation] = member();

    expect(fn () => resolve(AddMember::class)->handle($organisation, $user->email, OrganisationRole::Viewer))
        ->toThrow(ValidationException::class, 'They are already a member.');
});
