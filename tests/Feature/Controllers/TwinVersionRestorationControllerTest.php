<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;

it('restores an old version as a new one', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $first = $twin->versions()->sole();
    $twin->versions()->create(['number' => 2, 'definition' => ['changed' => true]]);

    $this->actingAs($user)->post(route('twins.versions.restore', [$twin, $first]))
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Saved version 3.']);

    $latest = $twin->latestVersion()->sole();
    expect($latest->note)->toBe('Restored version 1')
        ->and($latest->definition)->toBe($first->definition);
});

it('keeps viewers from restoring', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.versions.restore', [$twin, $twin->versions()->sole()]))->assertForbidden();
});
