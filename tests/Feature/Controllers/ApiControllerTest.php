<?php

declare(strict_types=1);

use App\Actions\RecordReadings;
use App\Enums\ApiTokenAccess;
use App\Enums\AuditAction;
use App\Models\Alert;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

const API_TOKEN = 'mrd_reporting-system-token-for-the-harbour-depot-1234';

/**
 * @return array{Organisation, ApiToken}
 */
function apiOrganisation(array $token = []): array
{
    $organisation = Organisation::factory()->create(['name' => 'Harbour Co']);
    $apiToken = ApiToken::factory()->for($organisation)->token(API_TOKEN)->create($token);

    return [$organisation, $apiToken];
}

it('says which token is in use and what it reaches', function (): void {
    [$organisation] = apiOrganisation(['name' => 'Reporting', 'expires_at' => '2027-01-01 00:00:00']);

    $this->withToken(API_TOKEN)->getJson(route('api.v1.token.show'))
        ->assertOk()
        ->assertExactJson(['data' => [
            'name' => 'Reporting',
            'access' => 'read',
            'expiresAt' => '2027-01-01T00:00:00+00:00',
            'organisation' => ['id' => $organisation->id, 'name' => 'Harbour Co'],
        ]]);
});

it('turns away a missing, unknown or expired token, before finding anything', function (): void {
    $twin = Twin::factory()->create();
    apiOrganisation(['expires_at' => now()->subMinute()]);

    $this->getJson(route('api.v1.twins.show', $twin))->assertUnauthorized()->assertExactJson(['message' => 'Missing or unknown API token.']);
    $this->getJson('/api/v1/twins/'.fake()->uuid())->assertUnauthorized();
    $this->withToken('mrd_not-a-real-one')->getJson(route('api.v1.twins.index'))->assertUnauthorized();
    $this->withToken(mb_substr(API_TOKEN, 4))->getJson(route('api.v1.twins.index'))->assertUnauthorized();
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.index'))->assertUnauthorized()->assertExactJson(['message' => 'This API token has expired.']);
});

it('notes when a token was last used, at most once a minute', function (): void {
    $this->freezeSecond();
    [, $token] = apiOrganisation();

    $this->withToken(API_TOKEN)->getJson(route('api.v1.token.show'))->assertOk();
    expect($token->refresh()->last_used_at?->equalTo(now()))->toBeTrue();

    $this->travel(30)->seconds();
    $this->withToken(API_TOKEN)->getJson(route('api.v1.token.show'))->assertOk();
    expect($token->refresh()->last_used_at?->equalTo(now()->subSeconds(30)))->toBeTrue();

    $this->travel(31)->seconds();
    $this->withToken(API_TOKEN)->getJson(route('api.v1.token.show'))->assertOk();
    expect($token->refresh()->last_used_at?->equalTo(now()))->toBeTrue();
});

it('limits each token to 120 requests a minute', function (): void {
    apiOrganisation();

    for ($i = 0; $i < 120; $i++) {
        $this->withToken(API_TOKEN)->getJson(route('api.v1.token.show'))->assertOk();
    }

    $this->withToken(API_TOKEN)->getJson(route('api.v1.token.show'))->assertTooManyRequests();
});

it("lists the organisation's projects and shows one, and nobody else's", function (): void {
    [$organisation] = apiOrganisation();
    $depots = Project::factory()->for($organisation)->create(['name' => 'Depots', 'description' => 'Cold stores']);
    Project::factory()->for($organisation)->create(['name' => 'Airports']);
    Twin::factory()->inProject($depots)->count(2)->create();
    $theirs = Project::factory()->create();

    $this->withToken(API_TOKEN)->getJson(route('api.v1.projects.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Airports')
        ->assertJsonPath('data.1', [
            'id' => $depots->id,
            'name' => 'Depots',
            'description' => 'Cold stores',
            'twins' => 2,
            'createdAt' => $depots->created_at->toIso8601String(),
            'updatedAt' => $depots->updated_at->toIso8601String(),
        ])
        ->assertJsonPath('meta.total', 2);

    $this->withToken(API_TOKEN)->getJson(route('api.v1.projects.show', $depots))->assertOk()->assertJsonPath('data.twins', 2);
    $this->withToken(API_TOKEN)->getJson(route('api.v1.projects.show', $theirs))->assertNotFound();
});

it("lists the organisation's twins, by project, and shows one with its assets and what's open", function (): void {
    $this->freezeSecond();
    [$organisation] = apiOrganisation();
    $project = Project::factory()->for($organisation)->create();
    $twin = twinIn($organisation);
    $twin->update(['project_id' => $project->id, 'name' => 'Harbour Depot', 'gateway_token_hash' => 'x', 'gateway_url' => 'https://gw.example.com', 'gateway_seen_at' => now()]);

    $other = Twin::factory()->for($organisation)->create(['name' => 'Airport']);
    Twin::factory()->create();
    Alert::factory()->for($twin)->create();
    Alert::factory()->for($twin)->cleared()->create();
    Issue::factory()->for($twin)->create();
    Issue::factory()->for($twin)->create();
    Issue::factory()->for($twin)->create(['closed_at' => now()]);

    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $other->id)
        ->assertJsonPath('data.0.gateway', ['connected' => false, 'online' => false, 'seenAt' => null])
        ->assertJsonMissingPath('data.0.assets')
        ->assertJsonMissingPath('data.0.version');
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.index', ['project' => $project->id]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Harbour Depot')
        ->assertJsonPath('links.first', route('api.v1.twins.index', ['project' => $project->id, 'page' => 1]));
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.index', ['project' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors('project');

    $response = $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.show', $twin))
        ->assertOk()
        ->assertJsonPath('data.projectId', $project->id)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.gateway', ['connected' => true, 'online' => true, 'seenAt' => now()->toIso8601String()])
        ->assertJsonPath('data.openAlerts', 1)
        ->assertJsonPath('data.openIssues', 2);
    expect(collect($response->json('data.assets'))->firstWhere('id', 'store'))
        ->toBe(['id' => 'store', 'name' => 'Seafood store', 'kind' => 'coldstore', 'zone' => 'yard'])
        ->and(collect($response->json('data.assets'))->pluck('kind')->filter(fn (string $kind): bool => $kind === '')->count())->toBe(0);
});

it('shows a twin with no version yet, and assets missing their names or kinds', function (): void {
    [$organisation] = apiOrganisation();
    $bare = Twin::factory()->for($organisation)->create();
    $twin = Twin::factory()->for($organisation)->create();
    $twin->versions()->create(['number' => 1, 'definition' => ['assets' => [['id' => 'tank'], 'junk', ['name' => 'no id']]]]);

    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.show', $bare))->assertOk()->assertJsonPath('data.version', null)->assertJsonMissingPath('data.assets');
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.show', $twin))
        ->assertJsonPath('data.assets', [['id' => 'tank', 'name' => 'tank', 'kind' => '', 'zone' => null]]);

    $twin->versions()->create(['number' => 2, 'definition' => ['assets' => 'none']]);
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.show', $twin))->assertJsonPath('data.assets', []);
});

it("keeps other organisations' twins hidden", function (): void {
    apiOrganisation();
    $twin = Twin::factory()->create();
    $issue = Issue::factory()->for($twin)->create();
    $comment = IssueComment::factory()->for($issue)->create(['photo' => $issue->storagePrefix().'/crack.png']);

    foreach ([
        route('api.v1.twins.show', $twin),
        route('api.v1.twins.readings.index', [$twin, 'asset' => 'store', 'metric' => 'tempC', 'from' => 0, 'to' => 1000]),
        route('api.v1.twins.alerts.index', $twin),
        route('api.v1.twins.issues.index', $twin),
        route('api.v1.twins.issues.show', [$twin, $issue]),
        route('api.v1.twins.issues.comments.photo', [$twin, $issue, $comment]),
    ] as $url) {
        $this->withToken(API_TOKEN)->getJson($url)->assertNotFound();
    }
});

it("serves a metric's history", function (): void {
    [$organisation] = apiOrganisation();
    $twin = twinIn($organisation);
    $at = now()->getTimestampMs();
    resolve(RecordReadings::class)->handle($twin, [['asset' => 'store', 'metric' => 'tempC', 'value' => -18.0, 'at' => $at - 5000]]);

    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.readings.index', [$twin, 'asset' => 'store', 'metric' => 'tempC', 'from' => $at - 60_000, 'to' => $at, 'buckets' => 60]))
        ->assertOk()
        ->assertJsonPath('data.bucketMs', 1000)
        ->assertJsonPath('data.points.0.mean', -18)
        ->assertJsonPath('data.points.0.count', 1);
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.readings.index', [$twin, 'metric' => 'Bad']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asset', 'metric', 'from', 'to']);
});

it("pages through a twin's alerts, newest first, open or since a time", function (): void {
    $this->freezeSecond();
    [$organisation] = apiOrganisation();
    $twin = twinIn($organisation);
    $ana = User::factory()->create(['name' => 'Ana']);
    $old = Alert::factory()->for($twin)->cleared()->create(['raised_at' => now()->subDays(2)]);
    $open = Alert::factory()->for($twin)->create(['raised_at' => now()->subHour(), 'acknowledged_at' => now(), 'acknowledged_by' => $ana->id, 'value' => 8.5]);
    Alert::factory()->for($twin)->count(50)->cleared()->create(['raised_at' => now()->subDays(3)]);

    $page = $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.alerts.index', $twin))
        ->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('data.0', [
            'id' => $open->id,
            'twinId' => $twin->id,
            'rule' => $open->rule,
            'asset' => $open->asset,
            'severity' => $open->severity->value,
            'message' => $open->message,
            'value' => 8.5,
            'raisedAt' => now()->subHour()->toIso8601String(),
            'clearedAt' => null,
            'acknowledgedAt' => now()->toIso8601String(),
            'acknowledgedBy' => 'Ana',
        ])
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('data.1.acknowledgedBy', null);
    $this->withToken(API_TOKEN)->getJson((string) $page->json('links.next'))->assertJsonCount(2, 'data');

    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.alerts.index', [$twin, 'open' => 1]))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.alerts.index', [$twin, 'since' => now()->subDays(2)->subMinute()->toIso8601String()]))
        ->assertJsonCount(2, 'data');
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.alerts.index', [$twin, 'open' => 'maybe', 'since' => 'yesterdayish']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['open', 'since']);
});

it("lists a twin's issues, open by default, and shows one with its comments", function (): void {
    $this->freezeSecond();
    Storage::fake('local');
    [$organisation] = apiOrganisation();
    $twin = twinIn($organisation);
    $ana = User::factory()->create(['name' => 'Ana']);
    $bo = User::factory()->create(['name' => 'Bo']);
    $alert = Alert::factory()->for($twin)->create();
    $issue = Issue::factory()->for($twin)->assigned($bo, '2026-01-01')->create(['author_id' => $ana->id, 'title' => 'Leak', 'lon' => 174.5, 'lat' => -36.5, 'height' => 12.0, 'alert_id' => $alert->id]);
    $closed = Issue::factory()->for($twin)->create(['closed_at' => now(), 'closed_by' => $ana->id]);
    $words = IssueComment::factory()->for($issue)->create(['author_id' => $ana->id, 'body' => 'Dripping', 'created_at' => now()->subMinute()]);
    $photo = IssueComment::factory()->for($issue)->create(['author_id' => null, 'body' => null, 'photo' => $issue->storagePrefix().'/drip.png']);
    Storage::disk('local')->put((string) $photo->photo, 'png');

    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.issues.index', $twin))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', [
            'id' => $issue->id,
            'twinId' => $twin->id,
            'number' => $issue->number,
            'title' => 'Leak',
            'at' => ['lon' => 174.5, 'lat' => -36.5, 'height' => 12],
            'author' => 'Ana',
            'assignee' => 'Bo',
            'dueOn' => '2026-01-01',
            'overdue' => true,
            'alertId' => $alert->id,
            'createdAt' => now()->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
            'closedAt' => null,
            'closedBy' => null,
            'url' => route('twins.show', [$twin, 'issue' => $issue->id]),
        ]);
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.issues.index', [$twin, 'state' => 'closed']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $closed->id)
        ->assertJsonPath('data.0.closedBy', 'Ana');
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.issues.index', [$twin, 'state' => 'all']))->assertJsonCount(2, 'data');
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.issues.index', [$twin, 'state' => 'mine']))->assertJsonValidationErrors('state');

    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.issues.show', [$twin, $issue]))
        ->assertOk()
        ->assertJsonPath('data.comments', [
            ['id' => $words->id, 'author' => 'Ana', 'body' => 'Dripping', 'photo' => null, 'createdAt' => now()->subMinute()->toIso8601String()],
            ['id' => $photo->id, 'author' => null, 'body' => null, 'photo' => route('api.v1.twins.issues.comments.photo', [$twin, $issue, $photo]), 'createdAt' => now()->toIso8601String()],
        ]);

    $this->withToken(API_TOKEN)->get(route('api.v1.twins.issues.comments.photo', [$twin, $issue, $photo]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    $this->withToken(API_TOKEN)->get(route('api.v1.twins.issues.comments.photo', [$twin, $issue, $words]))->assertNotFound();
    $this->withToken(API_TOKEN)->getJson(route('api.v1.twins.issues.show', [twinIn($organisation), $issue]))->assertNotFound();
});

it("gives the organisation's audit log, newest first, by cursor", function (): void {
    [$organisation] = apiOrganisation();
    $old = AuditEvent::factory()->for($organisation)->create(['created_at' => now()->subDays(2), 'action' => AuditAction::ProjectCreated]);
    $new = AuditEvent::factory()->for($organisation)->create(['action' => AuditAction::MemberAdded]);
    AuditEvent::factory()->create();

    $this->withToken(API_TOKEN)->getJson(route('api.v1.audit.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('links.next', null);

    $this->withToken(API_TOKEN)->getJson(route('api.v1.audit.index', ['since' => now()->subDay()->toIso8601String()]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'member.added');

    $this->withToken(API_TOKEN)->getJson(route('api.v1.audit.index', ['area' => 'projects']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $old->id);

    $this->withToken(API_TOKEN)->getJson(route('api.v1.audit.index', ['since' => 'yesterday-ish']))->assertUnprocessable()->assertJsonValidationErrors('since');
});

it('records what a token does under its own name', function (): void {
    [$organisation] = apiOrganisation(['name' => 'Maintenance system', 'access' => ApiTokenAccess::Write]);
    $twin = twinIn($organisation);
    $alert = Alert::factory()->for($twin)->create();

    $this->withToken(API_TOKEN)->postJson(route('api.v1.twins.alerts.acknowledgement.store', [$twin, $alert]))->assertSuccessful();

    expect(AuditEvent::query()->sole())
        ->action->toBe(AuditAction::AlertAcknowledged)
        ->actor_id->toBeNull()
        ->actorLabel()->toBe('Maintenance system (API)');
});
