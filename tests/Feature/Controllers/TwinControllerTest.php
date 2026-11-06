<?php

declare(strict_types=1);

use App\Actions\StoreTwinAsset;
use App\Enums\OrganisationRole;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('lists the twins in the current organisation only', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    [, $other] = member();
    twinIn($other);

    $this->actingAs($user)->get(route('twins.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('twins/index')
            ->has('twins', 1)
            ->where('twins.0.id', $twin->id)
            ->where('twins.0.version', 1)
            ->where('twins.0.assets', 0)
            ->where('canCreate', false));
});

it('sends the dashboard to the twins', function (): void {
    [$user] = member();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirectToRoute('twins.index');
});

it('offers the samples when creating', function (): void {
    [$user] = member(OrganisationRole::Admin);

    $this->actingAs($user)->get(route('twins.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('twins/create')
            ->where('samples', [['id' => 'northshore', 'name' => 'Northshore Cold Chain Hub'], ['id' => 'harbour-depot', 'name' => 'Harbour Depot']]));
});

it('keeps operators and viewers from creating', function (OrganisationRole $role): void {
    [$user] = member($role);

    $this->actingAs($user)->get(route('twins.create'))->assertForbidden();
    $this->actingAs($user)->post(route('twins.store'), ['name' => 'X', 'sample' => 'northshore'])->assertForbidden();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it('creates a twin from a sample', function (): void {
    [$user, $organisation] = member();

    $response = $this->actingAs($user)->post(route('twins.store'), ['name' => 'North', 'description' => 'Hub', 'sample' => 'northshore']);

    $twin = $organisation->twins()->sole();
    $response->assertRedirectToRoute('twins.show', $twin);
    expect($twin->description)->toBe('Hub')
        ->and($twin->latestVersion?->definition['tilesets'][0]['id'])->toBe('context');
});

it('creates a twin from an uploaded definition', function (): void {
    [$user, $organisation] = member();
    $file = UploadedFile::fake()->createWithContent('site.json', (string) file_get_contents(public_path('samples/harbour-depot.json')));

    $this->actingAs($user)->post(route('twins.store'), ['name' => 'Mine', 'definition' => $file])->assertRedirect();

    expect($organisation->twins()->sole()->description)->toBeNull();
});

it('reports every problem in an uploaded definition', function (): void {
    [$user] = member();
    $file = UploadedFile::fake()->createWithContent('site.json', '{"assets": []}');

    $this->actingAs($user)->fromRoute('twins.create')->post(route('twins.store'), ['name' => 'Bad', 'definition' => $file])
        ->assertRedirectToRoute('twins.create')
        ->assertSessionHasErrors('definition');

    expect(session('errors')->first('definition'))->toStartWith("Missing id\nMissing name\n");
});

it('needs a sample or a definition', function (): void {
    [$user] = member();

    $this->actingAs($user)->post(route('twins.store'), ['name' => 'Empty'])->assertSessionHasErrors(['sample', 'definition']);
    $this->actingAs($user)->post(route('twins.store'), ['name' => 'Odd', 'sample' => '../../.env'])->assertSessionHasErrors('sample');
});

it('shows a twin with its definition, history, uploads and abilities', function (OrganisationRole $role, bool $update, bool $operate): void {
    Storage::fake('local');
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    $asset = resolve(StoreTwinAsset::class)->handle($twin, $user, glbUpload(), 'Pump');

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('twins/show')
            ->where('twin.id', $twin->id)
            ->where('definition.id', $twin->id)
            ->where('version', 2)
            ->has('versions', 2)
            ->where('versions.0.number', 2)
            ->where('versions.0.author', $user->name)
            ->where('versions.1.author', null)
            ->where('assets.0.id', $asset->id)
            ->where('assets.0.url', $asset->url())
            ->where('can', ['update' => $update, 'operate' => $operate]));
})->with([
    [OrganisationRole::Owner, true, true],
    [OrganisationRole::Operator, false, true],
    [OrganisationRole::Viewer, false, false],
]);

it('hides twins from other organisations', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    [$outsider] = member();

    $this->actingAs($outsider)->get(route('twins.show', $twin))->assertForbidden();
});

it('renames a twin', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $twin = twinIn($organisation);

    $this->actingAs($user)->patch(route('twins.update', $twin), ['name' => 'Renamed', 'description' => ''])
        ->assertRedirectToRoute('twins.show', $twin);

    expect($twin->refresh()->name)->toBe('Renamed')->and($twin->description)->toBeNull();
    $this->actingAs($user)->patch(route('twins.update', $twin), ['name' => 'Again', 'description' => 'Kept']);
    expect($twin->refresh()->description)->toBe('Kept');
});

it('deletes a twin', function (): void {
    Storage::fake('local');
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->delete(route('twins.destroy', $twin))
        ->assertRedirectToRoute('twins.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Twin deleted.']);

    expect(Twin::query()->count())->toBe(0);
});

it('keeps viewers from changing twins', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);

    $this->actingAs($user)->patch(route('twins.update', $twin), ['name' => 'X'])->assertForbidden();
    $this->actingAs($user)->delete(route('twins.destroy', $twin))->assertForbidden();
});

it('needs an account', function (): void {
    $this->get(route('twins.index'))->assertRedirectToRoute('login');
});

it('shows the public demo without an account', function (): void {
    $this->get(route('demo'))->assertOk()->assertInertia(fn ($page) => $page->component('demo'));
});

it('creates a personal organisation on first visit', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('twins.index'))->assertOk();

    expect($user->refresh()->current_organisation_id)->not->toBeNull();
});
