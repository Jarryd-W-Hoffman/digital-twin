<?php

declare(strict_types=1);

use App\Actions\DeleteUser;
use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

it('may delete a user', function (): void {
    $user = User::factory()->create();

    $action = resolve(DeleteUser::class);

    $action->handle($user);

    expect($user->exists)->toBeFalse();
});

it('deletes an organisation the user is the only member of, with its projects and files', function (): void {
    Storage::fake('local');
    [$user, $organisation] = member();
    $project = twinIn($organisation)->project;
    Storage::disk('local')->put($project->storagePrefix().'/assets/a/model.glb', 'x');
    [, $other] = member();

    resolve(DeleteUser::class)->handle($user);

    expect($user->fresh())->toBeNull()
        ->and($organisation->fresh())->toBeNull()
        ->and(Project::query()->whereBelongsTo($organisation)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($other->fresh())->not->toBeNull();
});

it('leaves an organisation that has another owner', function (): void {
    [$user, $organisation] = member();
    $owner = User::factory()->create();
    $organisation->memberships()->create(['user_id' => $owner->id, 'role' => OrganisationRole::Owner]);

    resolve(DeleteUser::class)->handle($user);

    expect($user->fresh())->toBeNull()
        ->and($organisation->fresh())->not->toBeNull()
        ->and($organisation->roleOf($owner))->toBe(OrganisationRole::Owner)
        ->and($organisation->memberships()->count())->toBe(1);
});

it('leaves an organisation it is only a member of', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $owner = User::factory()->create();
    $organisation->memberships()->create(['user_id' => $owner->id, 'role' => OrganisationRole::Owner]);

    resolve(DeleteUser::class)->handle($user);

    expect($user->fresh())->toBeNull()
        ->and($organisation->fresh())->not->toBeNull();
});

it('refuses to leave members without an owner, and deletes nothing', function (): void {
    [$user, $alone] = member();
    [, $organisation] = member();
    $organisation->memberships()->create(['user_id' => $user->id, 'role' => OrganisationRole::Owner]);
    $organisation->memberships()->where('user_id', '!=', $user->id)->update(['role' => OrganisationRole::Admin]);

    expect(fn () => resolve(DeleteUser::class)->handle($user))
        ->toThrow(ValidationException::class, "You are the only owner of {$organisation->name}.");

    expect($user->fresh())->not->toBeNull()
        ->and($alone->fresh())->not->toBeNull()
        ->and(Organisation::query()->count())->toBe(2);
});
