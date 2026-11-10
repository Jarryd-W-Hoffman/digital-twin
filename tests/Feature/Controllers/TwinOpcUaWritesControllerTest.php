<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;

it('lets an editor allow and stop OPC UA writes', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $twin = twinIn($organisation);
    expect($twin->opcua_writes)->toBeFalse();

    $this->actingAs($user)->put(route('twins.opcua-writes.update', $twin), ['allowed' => true])
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast.message', 'Operators’ setpoints now go to the OPC UA servers.');
    expect($twin->refresh()->opcua_writes)->toBeTrue();

    $this->actingAs($user)->put(route('twins.opcua-writes.update', $twin), ['allowed' => false])
        ->assertInertiaFlash('toast.message', 'Writing to OPC UA is off.');
    expect($twin->refresh()->opcua_writes)->toBeFalse();
});

it('keeps operators and viewers from changing it', function (OrganisationRole $role): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);

    $this->actingAs($user)->put(route('twins.opcua-writes.update', $twin), ['allowed' => true])->assertForbidden();

    expect($twin->refresh()->opcua_writes)->toBeFalse();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it('needs a yes or no', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->put(route('twins.opcua-writes.update', $twin), ['allowed' => 'maybe'])->assertSessionHasErrors('allowed');
});
