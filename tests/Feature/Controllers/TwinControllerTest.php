<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Actions\IssueGatewayToken;
use App\Actions\RecordGatewayCheckIn;
use App\Enums\OrganisationRole;
use App\Models\Alert;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\OpcUaServer;
use App\Models\OpcUaWrite;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\SavedView;
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
            ->where('samples', [['id' => 'northshore', 'name' => 'Northshore Cold Chain Hub'], ['id' => 'harbour-depot', 'name' => 'Harbour Depot']])
            ->has('templates', 5)
            ->where('templates.0', ['id' => 'empty', 'name' => 'Empty', 'description' => "Nothing but the site's outline. Add models, scans and equipment from the Layout panel."]));
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

it('creates an empty twin at a place', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('twins.store'), [
        'project' => $project->id, 'name' => 'Quay', 'source' => 'blank', 'lat' => '-36.85', 'lon' => '174.76', 'utc_offset' => '13',
    ])->assertRedirect();

    $definition = $project->twins()->sole()->latestVersion?->definition;
    expect($definition['origin'])->toBe(['lon' => 174.76, 'lat' => -36.85])
        ->and($definition['utcOffsetHours'])->toEqual(13)
        ->and($definition['name'])->toBe('Quay')
        ->and($definition['assets'])->toBe([]);
});

it('creates a twin from a template at a place', function (string $template, string $first): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('twins.store'), [
        'project' => $project->id, 'name' => 'Pit', 'source' => 'blank', 'template' => $template, 'lat' => '-31.95', 'lon' => '115.86',
    ])->assertRedirect();

    $definition = $project->twins()->sole()->latestVersion?->definition;
    expect($definition['origin'])->toBe(['lon' => 115.86, 'lat' => -31.95])
        ->and($definition['assets'][0]['id'])->toBe($first);
})->with([['mine', 'sump'], ['factory', 'infeed'], ['survey', 'cp-1'], ['power', 'site']]);

it('refuses a template it does not have', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('twins.store'), ['project' => $project->id, 'name' => 'X', 'source' => 'blank', 'template' => 'volcano', 'lat' => 0, 'lon' => 0])
        ->assertSessionHasErrors(['template']);
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
            ->where('alerts', null)
            ->where('opcuaWrites', ['allowed' => false, 'recent' => []])
            ->where('can', ['update' => $update, 'share' => $update, 'operate' => $operate, 'manageTypes' => $update]));
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
            ->where('gateway.ticket', fn (string $ticket): bool => ticketClaims($ticket)['operate'] === false && ticketClaims($ticket)['edit'] === false));

    expect(hash('sha256', $token))->toBe($twin->refresh()->gateway_token_hash);
});

it('lets editors browse OPC UA servers through the gateway, and operators only operate', function (OrganisationRole $role, bool $operate, bool $edit): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    resolve(IssueGatewayToken::class)->handle($twin);
    resolve(RecordGatewayCheckIn::class)->handle($twin, 'ws://localhost:8788/ws');

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page->where('gateway.ticket', fn (string $ticket): bool => array_intersect_key(ticketClaims($ticket), ['operate' => 0, 'edit' => 0]) === ['operate' => $operate, 'edit' => $edit]));
})->with([
    'an admin' => [OrganisationRole::Admin, true, true],
    'an operator' => [OrganisationRole::Operator, true, false],
]);

/**
 * @return array<string, mixed>
 */
function ticketClaims(string $ticket): array
{
    /** @var array<string, mixed> */
    return json_decode(base64_decode(strtr(explode('.', $ticket)[0], '-_', '+/')), true);
}

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

it("lists the twin's latest OPC UA writes, newest first, with who asked", function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $twin->update(['opcua_writes' => true]);
    OpcUaWrite::factory()->for($twin)->create(['written_at' => now()->subHour(), 'user_id' => $user->id]);
    $latest = OpcUaWrite::factory()->for($twin)->failed()->create(['written_at' => now()]);
    OpcUaWrite::factory()->count(20)->for($twin)->create(['written_at' => now()->subDay()]);
    OpcUaWrite::factory()->create();

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page
            ->where('opcuaWrites.allowed', true)
            ->has('opcuaWrites.recent', 20)
            ->where('opcuaWrites.recent.0', [
                'id' => $latest->id,
                'at' => $latest->written_at->toIso8601String(),
                'user' => null,
                'server' => 'Plant PLC',
                'node' => 'ns=2;s=Jockey.Start',
                'asset' => 'jockey',
                'metric' => 'startKpa',
                'requested' => 600,
                'setpoint' => 600,
                'value' => 6,
                'error' => 'BadNotWritable',
            ])
            ->where('opcuaWrites.recent.1.user', $user->name));
});

it('passes the open alerts of a twin with a gateway, with who acknowledged them', function (): void {
    [$user] = member();
    [$twin] = gatewayTwin();
    $user->memberships()->create(['organisation_id' => $twin->organisation_id, 'role' => OrganisationRole::Operator]);
    $acknowledged = Alert::factory()->for($twin)->create(['raised_at' => now()->subHour(), 'acknowledged_at' => now(), 'acknowledged_by' => $user->id]);
    $latest = Alert::factory()->for($twin)->create(['rule' => 'tripped', 'asset' => 'plant']);
    Alert::factory()->for($twin)->cleared()->create();
    Alert::factory()->create();

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page->where('alerts', [
            ['id' => $latest->id, 'rule' => 'tripped', 'asset' => 'plant', 'acknowledgedBy' => null, 'acknowledgedAt' => null],
            ['id' => $acknowledged->id, 'rule' => 'warm', 'asset' => 'store', 'acknowledgedBy' => $user->name, 'acknowledgedAt' => $acknowledged->acknowledged_at?->toIso8601String()],
        ]));
});

it('passes the issues pinned in the scene, most recently active first', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $older = Issue::factory()->for($twin)->closed()->create(['updated_at' => now()->subDay()]);
    $newer = Issue::factory()->for($twin)->assigned($user, '-1 day')->create(['title' => 'Gate sticks']);
    IssueComment::factory()->count(2)->for($newer)->create();
    Issue::factory()->create();

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page
            ->has('issues', 2)
            ->where('issues.0', [
                'id' => $newer->id,
                'number' => 2,
                'title' => 'Gate sticks',
                'closed' => false,
                'at' => [174.7633, -36.8485, 12.5],
                'view' => ['lon' => 174.7629, 'lat' => -36.8489, 'height' => 60, 'heading' => 30, 'pitch' => -35, 'roll' => 0],
                'author' => $newer->author?->name,
                'assignee' => $user->name,
                'mine' => true,
                'dueOn' => today()->subDay()->format('Y-m-d'),
                'overdue' => true,
                'comments' => 2,
                'updatedAt' => $newer->updated_at->toIso8601String(),
            ])
            ->where('issues.1.id', $older->id)
            ->where('issues.1.closed', true)
            ->where('issues.1.assignee', null)
            ->where('issues.1.mine', false)
            ->where('issues.1.overdue', false)
            ->where('issue', null));
});

it('opens an issue with its conversation and what the viewer may do', function (OrganisationRole $role, bool $close, bool $delete): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->closed()->create();
    $first = IssueComment::factory()->for($issue)->create(['photo' => $issue->storagePrefix().'/a.jpg', 'created_at' => now()->subHour()]);
    $mine = IssueComment::factory()->for($issue)->create(['author_id' => $user->id]);

    $this->actingAs($user)->get(route('twins.show', [$twin, 'issue' => $issue->id]))
        ->assertInertia(fn ($page) => $page->where('issue', [
            'id' => $issue->id,
            'number' => 1,
            'title' => 'Cracked slab by the loading bay',
            'at' => [174.7633, -36.8485, 12.5],
            'view' => ['lon' => 174.7629, 'lat' => -36.8489, 'height' => 60, 'heading' => 30, 'pitch' => -35, 'roll' => 0],
            'author' => $issue->author?->name,
            'createdAt' => $issue->created_at->toIso8601String(),
            'closedAt' => $issue->closed_at?->toIso8601String(),
            'closedBy' => $issue->closer?->name,
            'assignee' => null,
            'dueOn' => null,
            'overdue' => false,
            'alert' => null,
            'can' => ['assign' => $close, 'close' => $close, 'delete' => $delete],
            'members' => $close ? [['id' => $user->id, 'name' => $user->name]] : [],
            'comments' => [
                [
                    'id' => $first->id,
                    'author' => $first->author?->name,
                    'body' => 'About a metre long, along the joint.',
                    'photo' => route('twins.issues.comments.photo', [$twin, $issue, $first], false),
                    'createdAt' => $first->created_at->toIso8601String(),
                    'canDelete' => $delete,
                ],
                [
                    'id' => $mine->id,
                    'author' => $user->name,
                    'body' => 'About a metre long, along the joint.',
                    'photo' => null,
                    'createdAt' => $mine->created_at->toIso8601String(),
                    'canDelete' => true,
                ],
            ],
        ]));
})->with([
    [OrganisationRole::Owner, true, true],
    [OrganisationRole::Operator, true, false],
    [OrganisationRole::Viewer, false, false],
]);

it('opens an issue with who has it, when it is due, and the alert it follows up', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $alert = Alert::factory()->for($twin)->create();
    $issue = Issue::factory()->for($twin)->assigned($user, '+3 days')->create(['alert_id' => $alert->id]);

    $this->actingAs($user)->get(route('twins.show', [$twin, 'issue' => $issue->id]))
        ->assertInertia(fn ($page) => $page
            ->where('issue.assignee', ['id' => $user->id, 'name' => $user->name])
            ->where('issue.dueOn', today()->addDays(3)->format('Y-m-d'))
            ->where('issue.overdue', false)
            ->where('issue.alert', [
                'message' => $alert->message,
                'asset' => $alert->asset,
                'raisedAt' => $alert->raised_at->toIso8601String(),
                'url' => route('twins.alerts.index', $twin, false),
            ])
            ->where('issue.can', ['assign' => false, 'close' => true, 'delete' => false])
            ->where('issue.members', []));
});

it("passes the alert an issue is raised from, if it's one of the twin's", function (string $which): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $alert = Alert::factory()->for($twin)->create();
    $elsewhere = Alert::factory()->create();
    $id = match ($which) {
        'own' => $alert->id,
        'elsewhere' => $elsewhere->id,
        default => 'not-a-uuid',
    };

    $this->actingAs($user)->get(route('twins.show', [$twin, 'raise' => $id]))
        ->assertInertia(fn ($page) => $page->where('raise', $which === 'own'
            ? ['id' => $alert->id, 'asset' => $alert->asset, 'message' => $alert->message]
            : null));
})->with(['own', 'elsewhere', 'not-a-uuid']);

it("opens no issue for an id that isn't one of the twin's", function (string $id): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $elsewhere = Issue::factory()->create();

    $this->actingAs($user)->get(route('twins.show', [$twin, 'issue' => $id === 'elsewhere' ? $elsewhere->id : $id]))
        ->assertInertia(fn ($page) => $page->where('issue', null));
})->with(['elsewhere', 'not-a-uuid']);

it('passes the views someone can see: theirs and the shared ones, with what they may do', function (OrganisationRole $role, bool $edits): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    $mine = SavedView::factory()->for($twin)->create(['author_id' => $user->id, 'name' => 'Mine', 'updated_at' => now()->subHour()]);
    $shared = SavedView::factory()->for($twin)->shared()->create(['name' => 'Night shift']);
    SavedView::factory()->for($twin)->create(['name' => "Someone else's"]);
    SavedView::factory()->shared()->create();
    $twin->update(['opening_view_id' => $shared->id]);

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page
            ->has('views', 2)
            ->where('views.0', [
                'id' => $shared->id,
                'name' => 'Night shift',
                'author' => $shared->author?->name,
                'mine' => false,
                'shared' => true,
                'opening' => true,
                'state' => $shared->state,
                'can' => ['update' => $edits, 'delete' => $edits, 'share' => $edits],
            ])
            ->where('views.1.id', $mine->id)
            ->where('views.1.mine', true)
            ->where('views.1.opening', false)
            ->where('views.1.can', ['update' => true, 'delete' => true, 'share' => $edits]));
})->with([
    [OrganisationRole::Owner, true],
    [OrganisationRole::Viewer, false],
]);

it('opens at a linked view, even one not shared, else at the opening view', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $opening = SavedView::factory()->for($twin)->shared()->create();
    $linked = SavedView::factory()->for($twin)->create(['state' => [...$opening->state, 'colorBy' => 'power']]);
    $elsewhere = SavedView::factory()->create();

    $this->actingAs($user)->get(route('twins.show', $twin))
        ->assertInertia(fn ($page) => $page->where('startView', null));

    $twin->update(['opening_view_id' => $opening->id]);

    $this->actingAs($user)->get(route('twins.show', [$twin, 'view' => $linked->id]))
        ->assertInertia(fn ($page) => $page->where('startView', ['id' => $linked->id, 'state' => $linked->state]));

    foreach ([null, $elsewhere->id, 'not-a-uuid'] as $id) {
        $this->actingAs($user)->get(route('twins.show', [$twin, 'view' => $id]))
            ->assertInertia(fn ($page) => $page->where('startView', ['id' => $opening->id, 'state' => $opening->state]));
    }
});
