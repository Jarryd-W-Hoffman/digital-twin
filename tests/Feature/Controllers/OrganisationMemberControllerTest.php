<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\User;

it('lists members and roles', function (OrganisationRole $role, bool $manage): void {
    [$user, $organisation] = member($role);
    $colleague = User::factory()->create(['name' => 'Bo']);
    $organisation->memberships()->create(['user_id' => $colleague->id, 'role' => OrganisationRole::Viewer]);

    $this->actingAs($user)->get(route('organisation.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('organisation/members')
            ->where('organisation.id', $organisation->id)
            ->has('members', 2)
            ->where('members.0.isYou', true)
            ->where('members.1.name', 'Bo')
            ->where('members.1.role', 'viewer')
            ->has('roles', 4)
            ->where('roles.0', ['value' => 'owner', 'label' => 'Owner'])
            ->where('canManage', $manage));
})->with([[OrganisationRole::Admin, true], [OrganisationRole::Viewer, false]]);

it('adds, re-roles and removes members', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $colleague = User::factory()->create(['email' => 'bo@example.com']);

    $this->actingAs($user)->post(route('organisation.members.store'), ['email' => 'bo@example.com', 'role' => 'operator'])
        ->assertRedirectToRoute('organisation.members.index');
    $membership = $organisation->memberships()->where('user_id', $colleague->id)->sole();
    expect($membership->role)->toBe(OrganisationRole::Operator);

    $this->actingAs($user)->patch(route('organisation.members.update', $membership), ['role' => 'admin'])
        ->assertRedirectToRoute('organisation.members.index');
    expect($membership->refresh()->role)->toBe(OrganisationRole::Admin);

    $this->actingAs($user)->delete(route('organisation.members.destroy', $membership))
        ->assertRedirectToRoute('organisation.members.index');
    expect($colleague->roleIn($organisation))->toBeNull();
});

it('validates new members', function (): void {
    [$user] = member();

    $this->actingAs($user)->post(route('organisation.members.store'), ['email' => 'ghost@example.com', 'role' => 'viewer'])
        ->assertSessionHasErrors(['email' => 'No one has an account with that email yet.']);
    $this->actingAs($user)->post(route('organisation.members.store'), ['email' => 'x@example.com', 'role' => 'emperor'])
        ->assertSessionHasErrors('role');
});

it('keeps the last owner', function (): void {
    [$user, $organisation] = member();
    $mine = $organisation->memberships()->sole();

    $this->actingAs($user)->patch(route('organisation.members.update', $mine), ['role' => 'viewer'])->assertSessionHasErrors('role');
});

it('keeps non-managers out', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $mine = $organisation->memberships()->sole();

    $this->actingAs($user)->post(route('organisation.members.store'), ['email' => 'a@example.com', 'role' => 'viewer'])->assertForbidden();
    $this->actingAs($user)->patch(route('organisation.members.update', $mine), ['role' => 'owner'])->assertForbidden();
    $this->actingAs($user)->delete(route('organisation.members.destroy', $mine))->assertForbidden();
});

it('keeps managers of one organisation out of another', function (): void {
    [$user] = member();
    [, $other] = member();

    $this->actingAs($user)->delete(route('organisation.members.destroy', $other->memberships()->sole()))->assertForbidden();
});
