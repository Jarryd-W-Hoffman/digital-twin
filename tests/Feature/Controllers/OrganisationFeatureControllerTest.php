<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Enums\OrganisationRole;

beforeEach(function (): void {
    config(['meridian.photos.nodeodm_url' => 'http://nodeodm:3000']);
});

it('lists the features for admins and owners', function (OrganisationRole $role): void {
    [$user, $organisation] = member($role);

    $this->actingAs($user)->get(route('organisation.features.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('organisation/features')
            ->where('currentOrganisation', ['id' => $organisation->id, 'name' => $organisation->name])
            ->where('features', [[
                'value' => 'photo-processing',
                'label' => 'Photo processing',
                'description' => Feature::PhotoProcessing->description(),
                'enabled' => false,
                'unavailable' => null,
            ]]));
})->with([OrganisationRole::Admin, OrganisationRole::Owner]);

it('says why a feature is not available on this server', function (): void {
    config(['meridian.photos.nodeodm_url' => null]);
    [$user, $organisation] = member();
    $organisation->update(['features' => [Feature::PhotoProcessing]]);

    $this->actingAs($user)->get(route('organisation.features.index'))
        ->assertInertia(fn ($page) => $page->where('features.0.enabled', true)
            ->where('features.0.unavailable', Feature::PhotoProcessing->unavailable()));
});

it('turns a feature on and off', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);

    $this->actingAs($user)->put(route('organisation.features.update', Feature::PhotoProcessing), ['enabled' => true])
        ->assertRedirect(route('organisation.features.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Photo processing is on for '.$organisation->name.'.']);

    expect($organisation->refresh()->hasFeature(Feature::PhotoProcessing))->toBeTrue();

    $this->actingAs($user)->put(route('organisation.features.update', Feature::PhotoProcessing), ['enabled' => false])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Photo processing is off for '.$organisation->name.'.']);

    expect($organisation->refresh()->hasFeature(Feature::PhotoProcessing))->toBeFalse();
});

it('refuses to turn on a feature this server does not offer', function (): void {
    config(['meridian.photos.nodeodm_url' => null]);
    [$user, $organisation] = member();

    $this->actingAs($user)->put(route('organisation.features.update', Feature::PhotoProcessing), ['enabled' => true])
        ->assertSessionHasErrors(['enabled' => Feature::PhotoProcessing->unavailable()]);

    expect($organisation->refresh()->features)->toBeNull();
});

it('keeps features from operators and viewers', function (OrganisationRole $role): void {
    [$user] = member($role);

    $this->actingAs($user)->get(route('organisation.features.index'))->assertForbidden();
    $this->actingAs($user)->put(route('organisation.features.update', Feature::PhotoProcessing), ['enabled' => true])->assertForbidden();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it('knows only the features there are', function (): void {
    [$user] = member();

    $this->actingAs($user)->put('/organisation/features/time-travel', ['enabled' => true])->assertNotFound();
});
