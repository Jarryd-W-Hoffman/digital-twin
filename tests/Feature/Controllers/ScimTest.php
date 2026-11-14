<?php

declare(strict_types=1);

use App\Actions\CreateScimToken;
use App\Actions\SignInWithSso;
use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\ScimUser;
use App\Models\SsoConnection;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A connection for harbour.example adding newcomers as operators, and its
 * provisioning token.
 *
 * @return array{SsoConnection, string}
 */
function scimConnection(): array
{
    $connection = SsoConnection::factory()->for(Organisation::factory()->state(['name' => 'Harbour Co']))->domains()->create(['default_role' => OrganisationRole::Operator]);

    return [$connection, resolve(CreateScimToken::class)->handle($connection)];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scimAna(array $overrides = []): array
{
    return [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'userName' => 'Ana@harbour.example',
        'externalId' => 'entra-ana',
        'name' => ['givenName' => 'Ana', 'familyName' => 'Ruiz'],
        'emails' => [['value' => 'ana@harbour.example', 'type' => 'work', 'primary' => true]],
        'active' => true,
        ...$overrides,
    ];
}

it('turns away requests without its provisioning token, in SCIM form', function (?string $token): void {
    SsoConnection::factory()->create();

    $this->withToken((string) $token)->getJson('/api/scim/v2/Users')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/scim+json')
        ->assertExactJson(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => '401', 'detail' => 'Missing or unknown provisioning token.']);
})->with([null, 'mrd_an-api-token', 'mrs_unknown']);

it('says what it supports', function (): void {
    [, $token] = scimConnection();

    $this->withToken($token)->getJson('/api/scim/v2/ServiceProviderConfig')
        ->assertOk()
        ->assertJsonPath('patch.supported', true)
        ->assertJsonPath('bulk.supported', false);
});

it('provisions a newcomer: an account to sign in to through single sign-on, and a membership', function (): void {
    $this->freezeSecond();
    [$connection, $token] = scimConnection();

    $response = $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna())->assertCreated();

    $user = User::query()->where('email', 'ana@harbour.example')->sole();
    $scimUser = ScimUser::query()->sole();
    $response->assertHeader('Content-Type', 'application/scim+json')->assertExactJson([
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'id' => $scimUser->id,
        'externalId' => 'entra-ana',
        'userName' => 'ana@harbour.example',
        'name' => ['formatted' => 'Ana Ruiz'],
        'displayName' => 'Ana Ruiz',
        'emails' => [['value' => 'ana@harbour.example', 'type' => 'work', 'primary' => true]],
        'active' => true,
        'meta' => ['resourceType' => 'User', 'created' => now()->toIso8601String(), 'lastModified' => now()->toIso8601String(), 'location' => route('scim.users.show', $scimUser->id)],
    ]);

    expect($user)->name->toBe('Ana Ruiz')->email_verified_at->not->toBeNull()->current_organisation_id->toBe($connection->organisation_id)
        ->and($user->roleIn($connection->organisation))->toBe(OrganisationRole::Operator)
        ->and($scimUser)->created_account->toBeTrue()->user_id->toBe($user->id)
        ->and(AuditEvent::query()->where('action', AuditAction::MemberAdded)->sole())
        ->actorLabel()->toBe('Provisioning (API)')
        ->details->toBe(['role' => 'operator', 'via' => 'provisioning']);

    // Their first single sign-on links the account the provider made.
    $signedIn = resolve(SignInWithSso::class)->handle($connection, ['subject' => 'ana-sub', 'email' => 'ana@harbour.example', 'name' => 'Ana Ruiz']);
    expect($signedIn->is($user))->toBeTrue()
        ->and($connection->identities()->sole())->subject->toBe('ana-sub');
});

it('takes an existing account in its domains as it is, without linking its single sign-on', function (): void {
    [$connection, $token] = scimConnection();
    $user = User::factory()->create(['email' => 'ana@harbour.example', 'name' => 'Ana R']);

    $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna(['active' => false]))->assertCreated()->assertJsonPath('active', false);

    expect(ScimUser::query()->sole())->created_account->toBeFalse()->user_id->toBe($user->id)
        ->and($user->roleIn($connection->organisation))->toBeNull();

    $this->withToken($token)->patchJson('/api/scim/v2/Users/'.ScimUser::query()->sole()->id, [
        'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => true], ['op' => 'replace', 'path' => 'name.givenName', 'value' => 'Anita']],
    ])->assertOk();

    expect($user->fresh())->name->toBe('Ana R')
        ->and($user->roleIn($connection->organisation))->toBe(OrganisationRole::Operator);

    resolve(SignInWithSso::class)->handle($connection, ['subject' => 'ana-sub', 'email' => 'ana@harbour.example', 'name' => null]);
})->throws(ValidationException::class, 'You already have a Meridian account.');

it('refuses what it cannot provision', function (array $user, int $status, string $detail): void {
    [, $token] = scimConnection();
    $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna(['userName' => 'taken@harbour.example', 'emails' => [['value' => 'taken@harbour.example']]]));
    User::factory()->create(['email' => 'bo@harbour.example']);
    $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna(['userName' => 'bo-1', 'emails' => [['value' => 'bo@harbour.example']]]));

    $this->withToken($token)->postJson('/api/scim/v2/Users', $user)
        ->assertStatus($status)
        ->assertJsonPath('status', (string) $status)
        ->assertJsonPath('detail', $detail);
})->with([
    'no userName' => [['emails' => [['value' => 'ana@harbour.example']]], 400, 'A userName is required.'],
    'no address' => [['userName' => 'ana'], 400, 'An email address is required, in emails or as the userName.'],
    'outside the domains' => [['userName' => 'ana@elsewhere.example'], 400, "ana@elsewhere.example isn't in Harbour Co's single sign-on domains."],
    'userName taken' => [['userName' => 'Taken@harbour.example'], 409, 'A user with that userName is already provisioned.'],
    'account taken' => [['userName' => 'bo-2', 'emails' => [['value' => 'bo@harbour.example']]], 409, 'bo@harbour.example is already provisioned under another userName.'],
]);

it('refuses a body that is not a JSON object', function (): void {
    [, $token] = scimConnection();

    $this->call('POST', '/api/scim/v2/Users', server: ['CONTENT_TYPE' => 'application/scim+json', 'HTTP_ACCEPT' => 'application/scim+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: 'nope')
        ->assertBadRequest()
        ->assertJsonPath('scimType', 'invalidSyntax');
});

it('finds people by userName or externalId, a page at a time', function (): void {
    [$connection, $token] = scimConnection();
    foreach (['ana', 'bo', 'cy'] as $name) {
        $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna(['userName' => "{$name}@harbour.example", 'externalId' => "entra-{$name}", 'emails' => []]));
    }

    ScimUser::factory()->create(['user_name' => 'elsewhere@harbour.example']);

    $this->withToken($token)->getJson('/api/scim/v2/Users?filter='.urlencode('userName eq "BO@harbour.example"'))
        ->assertOk()
        ->assertJsonPath('totalResults', 1)
        ->assertJsonPath('Resources.0.externalId', 'entra-bo');
    $this->withToken($token)->getJson('/api/scim/v2/Users?filter='.urlencode('externalId eq "entra-cy"'))->assertJsonPath('Resources.0.userName', 'cy@harbour.example');
    $this->withToken($token)->getJson('/api/scim/v2/Users?filter='.urlencode('userName eq "nobody"'))->assertJsonPath('totalResults', 0)->assertJsonPath('Resources', []);
    $this->withToken($token)->getJson('/api/scim/v2/Users?startIndex=2&count=1')
        ->assertExactJsonStructure(['schemas', 'totalResults', 'startIndex', 'itemsPerPage', 'Resources' => [['schemas', 'id', 'externalId', 'userName', 'name', 'displayName', 'emails', 'active', 'meta']]])
        ->assertJsonPath('totalResults', 3)
        ->assertJsonPath('startIndex', 2)
        ->assertJsonPath('itemsPerPage', 1)
        ->assertJsonPath('Resources.0.userName', 'bo@harbour.example');
    $this->withToken($token)->getJson('/api/scim/v2/Users?filter='.urlencode('emails co "harbour"'))
        ->assertBadRequest()
        ->assertJsonPath('scimType', 'invalidFilter');

    expect($connection->fresh()->scim_token_used_at)->not->toBeNull();
});

it("shows, replaces and deletes one of its own people, and nobody else's", function (): void {
    [$connection, $token] = scimConnection();
    $id = $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna())->json('id');
    $other = ScimUser::factory()->create();

    $this->withToken($token)->getJson("/api/scim/v2/Users/{$id}")->assertOk()->assertJsonPath('userName', 'ana@harbour.example');
    $this->withToken($token)->getJson("/api/scim/v2/Users/{$other->id}")->assertNotFound()->assertJsonPath('detail', 'No provisioned user with that id.');

    $this->withToken($token)->putJson("/api/scim/v2/Users/{$id}", scimAna(['userName' => 'ana.ruiz@harbour.example', 'name' => ['formatted' => 'Ana Ruiz-Ng'], 'emails' => [['value' => 'ana.ruiz@harbour.example']]]))
        ->assertOk()
        ->assertJsonPath('userName', 'ana.ruiz@harbour.example')
        ->assertJsonPath('displayName', 'Ana Ruiz-Ng')
        ->assertJsonPath('emails.0.value', 'ana@harbour.example');

    $user = User::query()->where('email', 'ana@harbour.example')->sole();
    $this->withToken($token)->deleteJson("/api/scim/v2/Users/{$id}")->assertNoContent();

    expect(ScimUser::query()->whereKey($id)->exists())->toBeFalse()
        ->and($user->fresh())->not->toBeNull()
        ->and($user->roleIn($connection->organisation))->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::MemberRemoved)->sole()->actorLabel())->toBe('Provisioning (API)');
});

it('refuses renaming someone to a userName another has', function (): void {
    [, $token] = scimConnection();
    $id = $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna())->json('id');
    $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna(['userName' => 'bo@harbour.example', 'emails' => []]));

    $this->withToken($token)->putJson("/api/scim/v2/Users/{$id}", scimAna(['userName' => 'bo@harbour.example']))->assertConflict()->assertJsonPath('scimType', 'uniqueness');
});

it('deactivates someone the way Entra does, ending their access at once, and brings them back', function (): void {
    [$connection, $token] = scimConnection();
    $id = $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna())->json('id');
    $user = User::query()->where('email', 'ana@harbour.example')->sole();
    $twin = twinIn($connection->organisation);

    $this->actingAs($user)->get(route('twins.show', $twin))->assertOk();

    $this->withToken($token)->patchJson("/api/scim/v2/Users/{$id}", [
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
        'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']],
    ])->assertOk()->assertJsonPath('active', false);

    expect($user->roleIn($connection->organisation))->toBeNull();
    $this->actingAs($user->fresh())->get(route('twins.show', $twin))->assertForbidden();

    $this->withToken($token)->patchJson("/api/scim/v2/Users/{$id}", ['Operations' => [['op' => 'replace', 'value' => ['active' => true, 'displayName' => 'Ana']]]])->assertJsonPath('active', true);

    expect($user->roleIn($connection->organisation))->toBe(OrganisationRole::Operator)
        ->and($user->fresh()->name)->toBe('Ana Ruiz');
});

it('never removes an owner, and notes that it kept them', function (): void {
    [$connection, $token] = scimConnection();
    [$owner] = [User::factory()->create(['email' => 'owner@harbour.example'])];
    $connection->organisation->memberships()->create(['user_id' => $owner->id, 'role' => OrganisationRole::Owner]);
    $id = $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna(['userName' => 'owner@harbour.example', 'emails' => []]))->json('id');

    $this->withToken($token)->patchJson("/api/scim/v2/Users/{$id}", ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]])->assertOk();
    $this->withToken($token)->deleteJson("/api/scim/v2/Users/{$id}")->assertNoContent();

    expect($owner->roleIn($connection->organisation))->toBe(OrganisationRole::Owner)
        ->and(AuditEvent::query()->where('action', AuditAction::ScimOwnerKept)->count())->toBe(2);
});

it('refuses malformed patches', function (array $body, string $detail): void {
    [, $token] = scimConnection();
    $id = $this->withToken($token)->postJson('/api/scim/v2/Users', scimAna())->json('id');

    $this->withToken($token)->patchJson("/api/scim/v2/Users/{$id}", $body)->assertBadRequest()->assertJsonPath('detail', $detail);
})->with([
    [[], 'A PATCH needs Operations.'],
    [['Operations' => ['replace']], 'Each operation needs an op.'],
    [['Operations' => [['op' => 'move']]], 'Unknown op: move.'],
    [['Operations' => [['op' => 'replace', 'value' => 'x']]], 'An operation without a path needs an object value.'],
]);

it('provisions a directory in bursts, within a limit per connection', function (): void {
    [$connection, $token] = scimConnection();
    for ($i = 0; $i < 600; $i++) {
        RateLimiter::hit(md5('scim'.'scim:'.$connection->id));
    }

    $this->withToken($token)->getJson('/api/scim/v2/Users')->assertTooManyRequests();
});

it('lets owners make, replace and revoke the provisioning token, shown once', function (): void {
    $this->freezeSecond();
    [$owner, $organisation] = member();
    $connection = SsoConnection::factory()->for($organisation)->domains()->create();

    $this->actingAs($owner)->get(route('organisation.sso.show'))->assertInertia(fn ($page) => $page
        ->where('connection.scim', ['hint' => null, 'usedAt' => null, 'provisioned' => 0])
        ->where('scimUrl', url('/api/scim/v2')));

    $this->post(route('organisation.sso.scim-token.store'))->assertRedirect(route('organisation.sso.show'))->assertInertiaFlash('scimToken');
    $first = (string) Inertia::getFlashed()['scimToken'];
    $connection->refresh();

    expect($first)->toStartWith('mrs_')->toHaveLength(52)
        ->and($connection->scim_token_hint)->toBe(mb_substr($first, -4))
        ->and($connection->toArray())->not->toHaveKey('scim_token_hash');
    $this->withToken($first)->getJson('/api/scim/v2/Users')->assertOk();

    $this->actingAs($owner)->post(route('organisation.sso.scim-token.store'));
    $this->withToken($first)->getJson('/api/scim/v2/Users')->assertUnauthorized();

    ScimUser::factory()->for($connection, 'connection')->create();
    ScimUser::factory()->for($connection, 'connection')->create(['active' => false]);
    $this->actingAs($owner)->get(route('organisation.sso.show'))->assertInertia(fn ($page) => $page
        ->where('connection.scim.hint', $connection->fresh()->scim_token_hint)
        ->where('connection.scim.provisioned', 1));

    Context::flush();
    $this->delete(route('organisation.sso.scim-token.destroy'))->assertRedirect(route('organisation.sso.show'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Provisioning token revoked.']);

    expect($connection->fresh())->scim_token_hash->toBeNull()->scim_token_hint->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::ScimTokenCreated)->pluck('details')->all())->toBe([null, ['replaced' => 'yes']])
        ->and(AuditEvent::query()->where('action', AuditAction::ScimTokenRevoked)->sole()->actorLabel())->toBe($owner->name);
});

it('keeps the provisioning token to owners of an organisation with single sign-on', function (OrganisationRole $role, bool $connected, int $status): void {
    [$user, $organisation] = member($role);
    if ($connected) {
        SsoConnection::factory()->for($organisation)->create();
    }

    $this->actingAs($user)->post(route('organisation.sso.scim-token.store'))->assertStatus($status);
    $this->delete(route('organisation.sso.scim-token.destroy'))->assertStatus($status);
})->with([
    [OrganisationRole::Admin, true, 403],
    [OrganisationRole::Owner, false, 404],
]);
