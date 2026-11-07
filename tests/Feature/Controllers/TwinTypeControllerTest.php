<?php

declare(strict_types=1);

use App\Actions\SaveTwinVersion;
use App\Actions\SaveTypeToLibrary;
use App\Enums\OrganisationRole;
use App\Models\AssetType;
use App\Support\SampleTwins;

it('adds a library type to the twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $type = AssetType::factory()->for($organisation)->create(['name' => 'Flow meter']);

    $this->actingAs($user)->post(route('twins.types.store', $twin), ['type' => $type->id])
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Flow meter added from the library.']);

    expect($twin->latestVersion()->sole()->definition['types'])->toBe([$type->definition]);

    $this->actingAs($user)->post(route('twins.types.store', $twin), ['type' => $type->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'This twin already has Flow meter.']);
    expect($twin->versions()->count())->toBe(2);
});

it('says why a type does not fit the twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $type = resolve(SaveTypeToLibrary::class)->handle($organisation, null, northshoreType('jockey-pump'));

    $this->actingAs($user)->post(route('twins.types.store', $twin), ['type' => $type->id])
        ->assertSessionHasErrors(['type' => "Jockey pump can’t be added to this twin:\nType jockey-pump: transition to running refers to unknown asset fire-tank\nType jockey-pump: transition to idle refers to unknown asset fire-tank"]);
});

it("only adds types from the twin's organisation", function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.types.store', $twin), ['type' => AssetType::factory()->create()->id])->assertSessionHasErrors('type');
    $this->actingAs($user)->post(route('twins.types.store', $twin), ['type' => 'nope'])->assertSessionHasErrors('type');
});

it("saves the twin's type to the library and shows how they compare", function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    resolve(SaveTwinVersion::class)->handle($twin, null, resolve(SampleTwins::class)->definition('northshore'));
    resolve(SaveTypeToLibrary::class)->handle($organisation, null, [...northshoreType('jockey-pump'), 'name' => 'Old pump']);
    AssetType::factory()->for($organisation)->create(['slug' => 'meter', 'name' => 'Meter']);

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page
            ->where('types.0.slug', 'meter')
            ->where('types.0.inTwin', null)
            ->where('types.1.slug', 'jockey-pump')
            ->where('types.1.inTwin', 'different'));

    $this->actingAs($user)->put(route('twins.types.library.update', [$twin, 'jockey-pump']))
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Jockey pump saved to the library.']);

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page->where('types.0.slug', 'jockey-pump')->where('types.0.inTwin', 'same'));
    expect($organisation->assetTypes()->where('slug', 'jockey-pump')->sole()->user_id)->toBe($user->id);
});

it("can't save a type the twin doesn't have", function (): void {
    [$user, $organisation] = member();

    $this->actingAs($user)->put(route('twins.types.library.update', [twinIn($organisation), 'nope']))->assertNotFound();
});

it('keeps those who cannot edit the twin out', function (OrganisationRole $role): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    $type = AssetType::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('twins.types.store', $twin), ['type' => $type->id])->assertForbidden();
    $this->actingAs($user)->put(route('twins.types.library.update', [$twin, 'x']))->assertForbidden();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);
