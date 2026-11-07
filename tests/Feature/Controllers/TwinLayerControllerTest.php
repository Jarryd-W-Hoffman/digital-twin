<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Enums\OrganisationRole;
use App\Models\ProjectAsset;

it('adds a library upload to the twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create(['name' => 'Pump']);

    $this->actingAs($user)->post(route('twins.layers.store', $twin), ['asset' => $asset->id])
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pump added.']);

    expect($twin->latestVersion()->sole()->definition['models'][0]['assetId'])->toBe($asset->id);
});

it("only adds uploads from the twin's own project", function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $elsewhere = ProjectAsset::factory()->create();

    $this->actingAs($user)->post(route('twins.layers.store', $twin), ['asset' => $elsewhere->id])->assertSessionHasErrors('asset');
    $this->actingAs($user)->post(route('twins.layers.store', $twin), ['asset' => 'nope'])->assertSessionHasErrors('asset');
});

it('removes a layer and keeps the upload', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create(['name' => 'Pump']);
    resolve(AddAssetToTwin::class)->handle($twin, $user, $asset);

    $this->actingAs($user)->delete(route('twins.layers.destroy', [$twin, $asset]))
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pump removed from this twin.']);

    expect($twin->latestVersion()->sole()->definition['models'])->toBe([])
        ->and($asset->fresh())->not->toBeNull();
});

it('does not reach into other projects', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $elsewhere = ProjectAsset::factory()->create();

    $this->actingAs($user)->delete(route('twins.layers.destroy', [$twin, $elsewhere]))->assertNotFound();
});

it('keeps operators from changing layers', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create();

    $this->actingAs($user)->post(route('twins.layers.store', $twin), ['asset' => $asset->id])->assertForbidden();
    $this->actingAs($user)->delete(route('twins.layers.destroy', [$twin, $asset]))->assertForbidden();
});
