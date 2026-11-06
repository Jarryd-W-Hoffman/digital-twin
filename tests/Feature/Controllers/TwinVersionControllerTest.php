<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Support\SampleTwins;

it('saves an edited definition as a new version', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $definition = resolve(SampleTwins::class)->definition('harbour-depot');
    $definition['models'] = [['id' => 'skid', 'name' => 'Skid', 'url' => '/m.glb', 'placement' => ['east' => 4, 'north' => 2, 'up' => 0, 'headingDeg' => 90, 'scale' => 1]]];

    $this->actingAs($user)->post(route('twins.versions.store', $twin), ['definition' => $definition, 'note' => 'Placed skid'])
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Saved version 2.']);

    $latest = $twin->latestVersion()->sole();
    expect($latest->note)->toBe('Placed skid')
        ->and($latest->definition['models'][0]['placement']['east'])->toBe(4);

    $this->actingAs($user)->post(route('twins.versions.store', $twin), ['definition' => $definition]);
    expect($twin->latestVersion()->sole()->note)->toBeNull();
});

it('rejects a definition the viewer could not run', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $definition = resolve(SampleTwins::class)->definition('harbour-depot');
    $definition['models'] = [['id' => 'skid', 'url' => '/m.glb']];

    $this->actingAs($user)->post(route('twins.versions.store', $twin), ['definition' => $definition])
        ->assertSessionHasErrors(['definition' => 'Model skid: placement must be an object']);

    expect($twin->versions()->count())->toBe(1);
});

it('keeps operators from editing', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.versions.store', $twin), ['definition' => resolve(SampleTwins::class)->definition('harbour-depot')])
        ->assertForbidden();
});

it('downloads a version as JSON', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $twin->update(['name' => 'Quay Four']);

    $version = $twin->versions()->sole();

    $this->actingAs($user)->get(route('twins.versions.show', [$twin, $version]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="quay-four-v1.json"')
        ->assertJsonPath('id', $twin->id);
});

it('only finds versions through their own twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $other = twinIn($organisation);

    $this->actingAs($user)->get(route('twins.versions.show', [$twin, $other->versions()->sole()]))->assertNotFound();
});

it('hides versions from outsiders', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    [$outsider] = member();

    $this->actingAs($outsider)->get(route('twins.versions.show', [$twin, $twin->versions()->sole()]))->assertForbidden();
});
