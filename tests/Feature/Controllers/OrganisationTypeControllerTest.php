<?php

declare(strict_types=1);

use App\Actions\SaveTypeToLibrary;
use App\Enums\OrganisationRole;
use App\Models\AssetType;
use App\Support\SampleTwins;
use Illuminate\Http\UploadedFile;

function typeUpload(mixed $contents, string $name = 'types.json'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) json_encode($contents));
}

it('lists the library for every member, with what each type reads', function (OrganisationRole $role, bool $manage): void {
    [$user, $organisation] = member($role);
    $saved = resolve(SaveTypeToLibrary::class);
    $saved->handle($organisation, $user, northshoreType('water-tank'));
    $saved->handle($organisation, null, northshoreType('jockey-pump'));
    AssetType::factory()->create();

    $this->actingAs($user)->get(route('organisation.types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('organisation/types')
            ->where('organisation', ['id' => $organisation->id, 'name' => $organisation->name])
            ->has('types', 2)
            ->where('types.0.slug', 'water-tank')
            ->where('types.0.name', 'Fire water tank')
            ->where('types.0.color', '#3a7ca5')
            ->where('types.0.shape', 'cylinder')
            ->where('types.0.metrics', ['Level', 'Ring main pressure'])
            ->where('types.0.states', 0)
            ->where('types.0.reads', ['jockey'])
            ->where('types.0.author', $user->name)
            ->where('types.1.slug', 'jockey-pump')
            ->where('types.1.metrics', ['Power', 'Running for', 'Start below', 'Stop above'])
            ->where('types.1.states', 2)
            ->where('types.1.author', null)
            ->where('canManage', $manage));
})->with([[OrganisationRole::Admin, true], [OrganisationRole::Viewer, false]]);

it('describes a type with no visual or metrics as best it can', function (): void {
    [$user, $organisation] = member();
    AssetType::factory()->for($organisation)->create(['definition' => ['id' => 'odd', 'metrics' => ['x', ['unit' => ''], ['key' => 'flow', 'unit' => '']]]]);

    $this->actingAs($user)->get(route('organisation.types.index'))
        ->assertInertia(fn ($page) => $page
            ->where('types.0.color', null)
            ->where('types.0.shape', null)
            ->where('types.0.metrics', ['flow'])
            ->where('types.0.reads', []));
});

it('imports types from a file, replacing those with the same id', function (): void {
    [$user, $organisation] = member();
    resolve(SaveTypeToLibrary::class)->handle($organisation, null, [...northshoreType('water-tank'), 'name' => 'Old tank']);

    $this->actingAs($user)->post(route('organisation.types.store'), ['file' => typeUpload(resolve(SampleTwins::class)->definition('northshore'))])
        ->assertRedirectToRoute('organisation.types.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 types saved to the library.']);

    expect($organisation->assetTypes()->orderBy('slug')->pluck('name')->all())->toBe(['Jockey pump', 'Fire water tank']);

    $this->actingAs($user)->post(route('organisation.types.store'), ['file' => typeUpload(northshoreType('water-tank'))])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 type saved to the library.']);
});

it('refuses a bad file with every reason', function (): void {
    [$user, $organisation] = member();

    $this->actingAs($user)->post(route('organisation.types.store'), ['file' => typeUpload([...northshoreType('water-tank'), 'visual' => []])])
        ->assertSessionHasErrors(['file' => "Type water-tank: visual.color must be a #rrggbb colour\nType water-tank: visual.shape must be box or cylinder"]);
    $this->actingAs($user)->post(route('organisation.types.store'), ['file' => typeUpload(northshoreType('water-tank'), 'tank.txt')])
        ->assertSessionHasErrors('file');
    $this->actingAs($user)->post(route('organisation.types.store'))->assertSessionHasErrors('file');

    expect($organisation->assetTypes()->count())->toBe(0);
});

it('downloads a type as JSON', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $type = resolve(SaveTypeToLibrary::class)->handle($organisation, null, northshoreType('water-tank'));

    $this->actingAs($user)->get(route('organisation.types.show', $type))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="water-tank.json"')
        ->assertExactJson(northshoreType('water-tank'));
});

it('deletes a type', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $type = AssetType::factory()->for($organisation)->create(['name' => 'Flow meter']);

    $this->actingAs($user)->delete(route('organisation.types.destroy', $type))
        ->assertRedirectToRoute('organisation.types.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Flow meter deleted from the library. Twins using it keep their copy.']);

    expect($type->fresh())->toBeNull();
});

it('keeps operators, viewers and outsiders from changing the library', function (OrganisationRole $role): void {
    [$user, $organisation] = member($role);
    $type = AssetType::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('organisation.types.store'), ['file' => typeUpload(northshoreType('water-tank'))])->assertForbidden();
    $this->actingAs($user)->delete(route('organisation.types.destroy', $type))->assertForbidden();
    expect($type->fresh())->not->toBeNull();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it("hides other organisations' types", function (): void {
    [$user] = member();
    $type = AssetType::factory()->create();

    $this->actingAs($user)->get(route('organisation.types.show', $type))->assertForbidden();
    $this->actingAs($user)->delete(route('organisation.types.destroy', $type))->assertForbidden();
});
