<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\SavedView;
use App\Models\User;

function viewState(array $overrides = []): array
{
    return [
        'camera' => ['lon' => 174.761, 'lat' => -36.851, 'height' => 70, 'heading' => 20, 'pitch' => -30, 'roll' => 0],
        'colorBy' => 'power',
        'basemap' => 'osm',
        'layers' => ['labels' => true, 'flows' => false, 'zones' => true, 'context' => true, 'shadows' => true],
        'section' => ['above' => 6, 'across' => null],
        'selected' => 'chiller',
        ...$overrides,
    ];
}

it('lets anyone who can see the twin save a view, kept private to them', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);

    $this->actingAs($user)->from(route('twins.show', $twin))
        ->post(route('twins.views.store', $twin), ['name' => 'Chiller at night', 'state' => viewState()])
        ->assertRedirect(route('twins.show', $twin))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'View "Chiller at night" saved.']);

    $view = $twin->savedViews()->sole();
    expect($view->name)->toBe('Chiller at night')
        ->and($view->author_id)->toBe($user->id)
        ->and($view->shared)->toBeFalse()
        ->and($view->state)->toEqual([
            'camera' => ['lon' => 174.761, 'lat' => -36.851, 'height' => 70.0, 'heading' => 20.0, 'pitch' => -30.0, 'roll' => 0.0],
            'colorBy' => 'power',
            'basemap' => 'osm',
            'layers' => ['labels' => true, 'flows' => false, 'zones' => true, 'context' => true, 'shadows' => true],
            'section' => ['above' => 6.0, 'across' => null],
            'selected' => 'chiller',
        ]);
});

it('keeps a cut across, and treats a section that cuts nothing as none', function (array $section, ?array $kept): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.views.store', $twin), ['name' => 'Cut', 'state' => viewState(['section' => $section, 'selected' => null])])
        ->assertSessionHasNoErrors();

    expect($twin->savedViews()->sole()->state)
        ->section->toEqual($kept)
        ->selected->toBeNull();
})->with([
    'across' => [['above' => null, 'across' => ['offset' => -12, 'heading' => 90]], ['above' => null, 'across' => ['offset' => -12.0, 'heading' => 90.0]]],
    'nothing' => [['above' => null, 'across' => null], null],
]);

it('refuses a view that is not one', function (array $fields, string $error): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.views.store', $twin), $fields)
        ->assertSessionHasErrors($error);

    expect($twin->savedViews()->count())->toBe(0);
})->with([
    'no name' => [['name' => '', 'state' => viewState()], 'name'],
    'a long name' => [['name' => str_repeat('a', 101), 'state' => viewState()], 'name'],
    'no state' => [['name' => 'View'], 'state'],
    'extra state' => [['name' => 'View', 'state' => [...viewState(), 'script' => 'x']], 'state'],
    'a camera off the globe' => [['name' => 'View', 'state' => viewState(['camera' => ['lon' => 0, 'lat' => 91, 'height' => 1, 'heading' => 0, 'pitch' => 0, 'roll' => 0]])], 'state.camera.lat'],
    'an unknown colouring' => [['name' => 'View', 'state' => viewState(['colorBy' => 'mood'])], 'state.colorBy'],
    'an unknown basemap' => [['name' => 'View', 'state' => viewState(['basemap' => 'satellite'])], 'state.basemap'],
    'a layer missing' => [['name' => 'View', 'state' => viewState(['layers' => ['labels' => true]])], 'state.layers.flows'],
    'a cut too high' => [['name' => 'View', 'state' => viewState(['section' => ['above' => 20000, 'across' => null]])], 'state.section.above'],
    'a cut across without a heading' => [['name' => 'View', 'state' => viewState(['section' => ['above' => null, 'across' => ['offset' => 1]]])], 'state.section.across.heading'],
    'a long selection' => [['name' => 'View', 'state' => viewState(['selected' => str_repeat('a', 201)])], 'state.selected'],
]);

it('keeps outsiders out', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $view = SavedView::factory()->for($twin)->shared()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->post(route('twins.views.store', $twin), ['name' => 'View', 'state' => viewState()])->assertForbidden();
    $this->actingAs($outsider)->patch(route('twins.views.update', [$twin, $view]), ['name' => 'Mine'])->assertForbidden();
    $this->actingAs($outsider)->delete(route('twins.views.destroy', [$twin, $view]))->assertForbidden();
    $this->actingAs($outsider)->post(route('twins.views.opening.store', [$twin, $view]))->assertForbidden();
    $this->actingAs($outsider)->delete(route('twins.opening-view.destroy', $twin))->assertForbidden();

    expect($view->refresh()->name)->toBe('Chiller at night');
});

it('finds a view only under its own twin', function (): void {
    [$user, $organisation] = member();
    $view = SavedView::factory()->for(twinIn($organisation))->create(['author_id' => $user->id]);

    $this->actingAs($user)->patch(route('twins.views.update', [twinIn($organisation), $view]), ['name' => 'Moved'])
        ->assertNotFound();
});

it('lets the author rename a view and save a new look over it', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $view = SavedView::factory()->for($twin)->create(['author_id' => $user->id]);

    $this->actingAs($user)->patch(route('twins.views.update', [$twin, $view]), ['name' => 'Loading bay'])
        ->assertSessionHasNoErrors();
    $this->actingAs($user)->patch(route('twins.views.update', [$twin, $view]), ['state' => viewState(['colorBy' => 'status'])])
        ->assertSessionHasNoErrors();

    expect($view->refresh())
        ->name->toBe('Loading bay')
        ->state->colorBy->toBe('status');
});

it('refuses a renamed view without a name', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $view = SavedView::factory()->for($twin)->create(['author_id' => $user->id]);

    $this->actingAs($user)->patch(route('twins.views.update', [$twin, $view]), ['name' => ''])
        ->assertSessionHasErrors('name');
});

it('lets only editors share a view or stop sharing it', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);
    $view = SavedView::factory()->for($twin)->create(['author_id' => $user->id]);

    $this->actingAs($user)->patch(route('twins.views.update', [$twin, $view]), ['shared' => true])->assertForbidden();
    $this->actingAs($user)->patch(route('twins.views.update', [$twin, $view]), ['shared' => false, 'name' => 'Same'])
        ->assertSessionHasNoErrors();

    $editor = User::factory()->create(['current_organisation_id' => $organisation->id]);
    $organisation->memberships()->create(['user_id' => $editor->id, 'role' => OrganisationRole::Admin]);

    $view->update(['shared' => true]);

    $this->actingAs($user)->patch(route('twins.views.update', [$twin, $view]), ['shared' => false])->assertForbidden();
    $this->actingAs($editor)->patch(route('twins.views.update', [$twin, $view]), ['shared' => false])
        ->assertRedirect();

    expect($view->refresh())->shared->toBeFalse()->name->toBe('Same');
});

it('lets the author or an editor delete a view', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $theirs = SavedView::factory()->for($twin)->create(['author_id' => $user->id]);
    $someoneElses = SavedView::factory()->for($twin)->shared()->create();

    $this->actingAs($user)->delete(route('twins.views.destroy', [$twin, $someoneElses]))->assertForbidden();
    $this->actingAs($user)->delete(route('twins.views.destroy', [$twin, $theirs]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'View deleted.']);

    $this->assertModelMissing($theirs);
    $this->assertModelExists($someoneElses);
});

it('lets editors choose the view the twin opens at, sharing it', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $twin = twinIn($organisation);
    $view = SavedView::factory()->for($twin)->create(['author_id' => $user->id]);

    $this->actingAs($user)->post(route('twins.views.opening.store', [$twin, $view]))->assertRedirect();

    expect($twin->refresh()->opening_view_id)->toBe($view->id)
        ->and($view->refresh()->shared)->toBeTrue();

    $this->actingAs($user)->delete(route('twins.opening-view.destroy', $twin))->assertRedirect();

    expect($twin->refresh()->opening_view_id)->toBeNull();
});

it('keeps operators from choosing the opening view', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);
    $view = SavedView::factory()->for($twin)->create(['author_id' => $user->id]);

    $this->actingAs($user)->post(route('twins.views.opening.store', [$twin, $view]))->assertForbidden();
    $this->actingAs($user)->delete(route('twins.opening-view.destroy', $twin))->assertForbidden();
});
