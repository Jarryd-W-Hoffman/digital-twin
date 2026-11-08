<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Actions\IssueGatewayToken;
use App\Actions\RecordGatewayCheckIn;
use App\Enums\OrganisationRole;
use App\Models\OpcUaServer;
use App\Models\Project;
use App\Models\ProjectAsset;
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
            ->where('twins.0.project', ['id' => $twin->project_id, 'name' => $twin->project->name])
            ->where('canCreate', false));
});

it('offers the projects and samples when creating', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create(['name' => 'Harbour']);
    Project::factory()->create();

    $this->actingAs($user)->get(route('twins.create'))
        ->assertInertia(fn ($page) => $page->where('projects', [['id' => $project->id, 'name' => 'Harbour']])->where('project', null));
    $this->actingAs($user)->get(route('twins.create', ['project' => $project->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('twins/create')
            ->where('project', $project->id)
            ->where('samples', [['id' => 'northshore', 'name' => 'Northshore Cold Chain Hub'], ['id' => 'harbour-depot', 'name' => 'Harbour Depot']]));
});

it('keeps operators and viewers from creating', function (OrganisationRole $role): void {
    [$user, $organisation] = member($role);
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->get(route('twins.create'))->assertForbidden();
    $this->actingAs($user)->post(route('twins.store'), ['project' => $project->id, 'name' => 'X', 'source' => 'sample', 'sample' => 'northshore'])->assertForbidden();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it('creates a twin from a sample', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $response = $this->actingAs($user)->post(route('twins.store'), ['project' => $project->id, 'name' => 'North', 'description' => 'Hub', 'source' => 'sample', 'sample' => 'northshore']);

    $twin = $project->twins()->sole();
    $response->assertRedirectToRoute('twins.show', $twin);
    expect($twin->description)->toBe('Hub')
        ->and($twin->latestVersion?->definition['tilesets'][0]['id'])->toBe('context');
});

it('creates a blank twin at a place', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('twins.store'), [
        'project' => $project->id, 'name' => 'Quay', 'source' => 'blank', 'lat' => '-36.85', 'lon' => '174.76', 'utc_offset' => '13',
    ])->assertRedirect();

    $definition = $project->twins()->sole()->latestVersion?->definition;
    expect($definition['origin'])->toBe(['lon' => 174.76, 'lat' => -36.85])
        ->and($definition['utcOffsetHours'])->toEqual(13)
        ->and($definition['name'])->toBe('Quay')
        ->and(array_column($definition['assets'], 'kind'))->toBe(['site', 'weather', 'grid', 'bus']);
});

it("guesses a blank twin's offset from its longitude, and checks the place", function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('twins.store'), ['project' => $project->id, 'name' => 'Lisbon', 'source' => 'blank', 'lat' => 38.7, 'lon' => -9.1]);
    expect($project->twins()->sole()->latestVersion?->definition['utcOffsetHours'])->toEqual(-1);

    $this->actingAs($user)->post(route('twins.store'), ['project' => $project->id, 'name' => 'X', 'source' => 'blank', 'lat' => 91, 'lon' => 'east', 'utc_offset' => 20])
        ->assertSessionHasErrors(['lat', 'lon', 'utc_offset']);
});

it('creates a twin from an uploaded definition', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $file = UploadedFile::fake()->createWithContent('site.json', (string) file_get_contents(public_path('samples/harbour-depot.json')));

    $this->actingAs($user)->post(route('twins.store'), ['project' => $project->id, 'name' => 'Mine', 'source' => 'file', 'definition' => $file])->assertRedirect();

    expect($organisation->twins()->sole()->description)->toBeNull();
});

it("only creates twins in the organisation's own projects", function (): void {
    [$user] = member();
    $elsewhere = Project::factory()->create();

    $this->actingAs($user)->post(route('twins.store'), ['project' => $elsewhere->id, 'name' => 'X', 'source' => 'sample', 'sample' => 'northshore'])
        ->assertSessionHasErrors('project');
});

it('reports every problem in an uploaded definition', function (): void {
    [$user] = member();
    $file = UploadedFile::fake()->createWithContent('site.json', '{"assets": []}');

    $this->actingAs($user)->fromRoute('twins.create')->post(route('twins.store'), ['name' => 'Bad', 'source' => 'file', 'definition' => $file])
        ->assertRedirectToRoute('twins.create')
        ->assertSessionHasErrors('definition');

    expect(explode("\n", (string) session('errors')->first('definition')))->toContain('Missing id', 'Missing name', 'Missing camera');
});

it('needs a sample or a definition', function (): void {
    [$user] = member();

    $this->actingAs($user)->post(route('twins.store'), ['name' => 'Empty'])->assertSessionHasErrors(['project', 'source']);
    $this->actingAs($user)->post(route('twins.store'), ['name' => 'Empty', 'source' => 'sample'])->assertSessionHasErrors('sample')->assertSessionDoesntHaveErrors('definition');
    $this->actingAs($user)->post(route('twins.store'), ['name' => 'Empty', 'source' => 'file'])->assertSessionHasErrors('definition')->assertSessionDoesntHaveErrors('sample');
    $this->actingAs($user)->post(route('twins.store'), ['name' => 'Odd', 'source' => 'sample', 'sample' => '../../.env'])->assertSessionHasErrors('sample');
    $this->actingAs($user)->post(route('twins.store'), ['name' => 'Odd', 'source' => 'other'])->assertSessionHasErrors('source');
});

it('shows a twin with its definition, history, project library and abilities', function (OrganisationRole $role, bool $update, bool $operate): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create();
    resolve(AddAssetToTwin::class)->handle($twin, $user, $asset);
    ProjectAsset::factory()->for($twin->project)->processing()->create();
    OpcUaServer::factory()->for($twin->project)->create(['name' => 'Plant PLC']);

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('twins/show')
            ->where('twin.id', $twin->id)
            ->where('project', ['id' => $twin->project_id, 'name' => $twin->project->name, 'topicPrefix' => 'p/'.$twin->project->mqtt_key.'/', 'opcuaServers' => ['Plant PLC']])
            ->where('definition.id', $twin->id)
            ->where('version', 2)
            ->has('versions', 2)
            ->where('versions.0.number', 2)
            ->where('versions.0.author', $user->name)
            ->where('versions.1.author', null)
            ->has('assets', 1)
            ->where('assets.0.id', $asset->id)
            ->where('assets.0.url', $asset->url())
            ->where('types', [])
            ->where('gateway', null)
            ->where('can', ['update' => $update, 'operate' => $operate, 'manageTypes' => $update]));
})->with([
    [OrganisationRole::Owner, true, true],
    [OrganisationRole::Operator, false, true],
    [OrganisationRole::Viewer, false, false],
]);

it('reports the gateway, and gives viewers a ticket while it is online', function (): void {
    $this->freezeSecond();
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $token = resolve(IssueGatewayToken::class)->handle($twin);

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page->where('gateway', ['online' => false, 'url' => null, 'seenAt' => null, 'mqtt' => null, 'opcua' => null, 'ticket' => null]));

    resolve(RecordGatewayCheckIn::class)->handle($twin, 'ws://localhost:8788/ws', 'connected', '{"Plant PLC":"connected"}');

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page
            ->where('gateway.online', true)
            ->where('gateway.mqtt', 'connected')
            ->where('gateway.opcua', ['Plant PLC' => 'connected'])
            ->where('gateway.url', 'ws://localhost:8788/ws')
            ->where('gateway.seenAt', now()->toIso8601String())
            ->where('gateway.ticket', fn (string $ticket): bool => json_decode(base64_decode(strtr(explode('.', $ticket)[0], '-_', '+/')), true)['operate'] === false));

    expect(hash('sha256', $token))->toBe($twin->refresh()->gateway_token_hash);
});

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
        ->assertRedirectToRoute('projects.show', $twin->project_id)
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
