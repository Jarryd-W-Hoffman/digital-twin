<?php

declare(strict_types=1);

use App\Actions\EnsureAnotherOwner;
use App\Enums\OrganisationRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('allows the change when another owner remains', function (): void {
    [, $organisation] = member();
    $second = $organisation->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => OrganisationRole::Owner]);

    resolve(EnsureAnotherOwner::class)->handle($second);

    expect(true)->toBeTrue();
});

it('refuses to leave an organisation without an owner', function (): void {
    [, $organisation] = member();

    expect(fn () => resolve(EnsureAnotherOwner::class)->handle($organisation->memberships()->sole()))
        ->toThrow(ValidationException::class, 'An organisation needs at least one owner.');
});
