<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\Organisation;

it('creates an organisation and switches to it', function (): void {
    [$user] = member();

    $this->actingAs($user)->post(route('organisations.store'), ['name' => 'Second site'])->assertRedirectToRoute('twins.index');

    expect($user->refresh()->organisation()->name)->toBe('Second site');
});

it('needs a name', function (): void {
    [$user] = member();

    $this->actingAs($user)->post(route('organisations.store'), ['name' => ''])->assertSessionHasErrors('name');
});

it('switches between organisations the user belongs to', function (): void {
    [$user] = member();
    [, $other] = member();
    $other->memberships()->create(['user_id' => $user->id, 'role' => OrganisationRole::Viewer]);

    $this->actingAs($user)->put(route('organisations.current.update', $other))->assertRedirectToRoute('twins.index');

    expect($user->refresh()->current_organisation_id)->toBe($other->id);
});

it("refuses to switch into a stranger's organisation", function (): void {
    [$user] = member();

    $this->actingAs($user)->put(route('organisations.current.update', Organisation::factory()->create()))->assertForbidden();
});
