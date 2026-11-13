<?php

declare(strict_types=1);

use App\Actions\StartSsoSignIn;
use App\Enums\OrganisationRole;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Support\OpenIdConnect;
use Illuminate\Support\Facades\Http;

/**
 * What a browser that started signing in keeps in its session.
 *
 * @return array<string, array<string, mixed>>
 */
function ssoFlow(SsoConnection $connection, ?User $linking = null): array
{
    return [StartSsoSignIn::SESSION => [
        'connection' => $connection->id,
        'state' => 'state-from-the-browser',
        'nonce' => 'nonce-from-the-browser',
        'verifier' => 'verifier-from-the-browser',
        'link' => $linking?->id,
    ]];
}

function callback(array $query = []): string
{
    return route('sso.callback', ['state' => 'state-from-the-browser', 'code' => 'code-1', ...$query]);
}

it('asks for a work email address', function (): void {
    $this->get(route('sso.create'))->assertOk()->assertInertia(fn ($page) => $page->component('sso/create'));
    $this->get(route('login'))->assertOk();
});

it("sends the browser to the email domain's identity provider", function (): void {
    fakeIdentityProvider();
    $connection = SsoConnection::factory()->domains()->create();

    $response = $this->post(route('sso.store'), ['email' => 'Ana@Harbour.example'])->assertRedirect();
    $flow = session(StartSsoSignIn::SESSION);
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($response->headers->get('Location'))->toStartWith(IDP_ISSUER.'/protocol/openid-connect/auth?')
        ->and($flow)->connection->toBe($connection->id)->link->toBeNull()
        ->and($query)->state->toBe($flow['state'])->nonce->toBe($flow['nonce'])
        ->code_challenge->toBe(OpenIdConnect::challenge($flow['verifier']))
        ->redirect_uri->toBe(route('sso.callback'))
        ->login_hint->toBe('Ana@Harbour.example');

    $this->withHeader('X-Inertia', 'true')->post(route('sso.store'), ['email' => 'ana@harbour.example'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('says when no organisation signs the domain in, or its provider is gone', function (): void {
    $this->post(route('sso.store'), ['email' => 'ana@gmail.example'])
        ->assertSessionHasErrors(['email' => 'No organisation signs in gmail.example through single sign-on. Sign in with your password instead.']);
    $this->post(route('sso.store'), ['email' => 'ana'])->assertSessionHasErrors('email');

    SsoConnection::factory()->domains()->create();
    Http::fake(['*' => Http::response('', 500)]);
    $this->post(route('sso.store'), ['email' => 'ana@harbour.example'])->assertSessionHasErrors(['email' => 'The identity provider has no OpenID Connect discovery document at '.IDP_ISSUER.'/.well-known/openid-configuration.']);
});

it('signs in someone the provider vouches for, in its organisation', function (): void {
    fakeIdentityProvider();
    $connection = SsoConnection::factory()->domains()->autoJoin()->create();

    $this->withSession(ssoFlow($connection))->get(callback())->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'ana@harbour.example')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->current_organisation_id)->toBe($connection->organisation_id)
        ->and(session(StartSsoSignIn::SESSION))->toBeNull();
});

it('signs in a linked member and moves them to the organisation', function (): void {
    fakeIdentityProvider();
    $connection = SsoConnection::factory()->domains()->create();
    [$user] = member();
    $connection->organisation->memberships()->create(['user_id' => $user->id, 'role' => OrganisationRole::Viewer]);
    SsoIdentity::factory()->for($connection, 'connection')->for($user)->create(['subject' => 'ana-at-harbour']);

    $this->withSession(ssoFlow($connection))->get(callback())->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->current_organisation_id)->toBe($connection->organisation_id);
});

it("turns back answers that aren't to this browser, or say no", function (array $session, array $query, string $message): void {
    fakeIdentityProvider();
    $connection = SsoConnection::factory()->domains()->autoJoin()->create();

    $this->withSession($session === [] ? [] : ssoFlow($connection))->get(callback($query))
        ->assertRedirect(route('sso.create'))
        ->assertSessionHasErrors(['email' => $message]);
    $this->assertGuest();
})->with([
    'nothing started' => [[], [], "The sign-in didn't start here, or took too long. Try again."],
    'another state' => [['started'], ['state' => 'forged'], "The sign-in didn't start here, or took too long. Try again."],
    'no state' => [['started'], ['state' => null], "The sign-in didn't start here, or took too long. Try again."],
    'refused' => [['started'], ['error' => 'access_denied', 'code' => null], 'The identity provider said no (access_denied).'],
    'no code' => [['started'], ['code' => null], 'Single sign-on has been turned off for this organisation.'],
]);

it('says why someone the provider vouches for cannot come in', function (): void {
    fakeIdentityProvider();
    $connection = SsoConnection::factory()->domains()->create();

    $this->withSession(ssoFlow($connection))->get(callback())->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain("hasn't added you yet");
    $this->assertGuest();
});

it('turns back a token meant for another sign-in', function (): void {
    fakeIdentityProvider(['nonce' => 'replayed']);
    $connection = SsoConnection::factory()->domains()->autoJoin()->create();

    $this->withSession(ssoFlow($connection))->get(callback())->assertSessionHasErrors(['email' => "The sign-in doesn't match this browser. Try again."]);
    $this->assertGuest();
});

it('turns back an answer after single sign-on was turned off', function (): void {
    $connection = SsoConnection::factory()->domains()->create();
    $session = ssoFlow($connection);
    $connection->delete();

    $this->withSession($session)->get(callback())->assertSessionHasErrors(['email' => 'Single sign-on has been turned off for this organisation.']);
});

it('lists and links identities from settings', function (): void {
    fakeIdentityProvider();
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $connection = SsoConnection::factory()->for($organisation)->domains()->create();

    $this->actingAs($user)->get(route('user-sso.index'))
        ->assertInertia(fn ($page) => $page->component('user-sso/index')->where('identities', [])->where('available', ['organisation' => $organisation->name]));

    $this->post(route('user-sso.store'))->assertRedirect();
    expect(session(StartSsoSignIn::SESSION))->link->toBe($user->id)->connection->toBe($connection->id);

    $this->withSession(ssoFlow($connection, $user))->get(callback())->assertRedirect(route('user-sso.index'))->assertInertiaFlash('toast');

    $identity = $connection->identities()->sole();
    expect($identity)->user_id->toBe($user->id)->email->toBe('ana@harbour.example');

    $this->get(route('user-sso.index'))
        ->assertInertia(fn ($page) => $page->has('identities', 1)
            ->where('identities.0.id', $identity->id)
            ->where('identities.0.organisation', $organisation->name)
            ->where('identities.0.email', 'ana@harbour.example')
            ->where('available', null));

    $this->delete(route('user-sso.destroy', $identity))->assertRedirect(route('user-sso.index'));
    $this->assertModelMissing($identity);
});

it("won't link for someone else or without single sign-on, nor unlink someone else's", function (): void {
    fakeIdentityProvider();
    [$user, $organisation] = member();
    $connection = SsoConnection::factory()->domains()->create();
    $other = SsoIdentity::factory()->for($connection, 'connection')->create();

    $this->actingAs($user)->post(route('user-sso.store'))->assertNotFound();
    $this->get(route('user-sso.index'))->assertInertia(fn ($page) => $page->where('available', null));
    $this->delete(route('user-sso.destroy', $other))->assertNotFound();
    $this->withSession(ssoFlow($connection, User::factory()->create()))->get(callback())->assertForbidden();
    $this->withSession(ssoFlow($connection, $user))->get(callback(['state' => 'forged']))->assertSessionHasErrors(['sso' => "The sign-in didn't start here, or took too long. Try again."]);
    expect($organisation->id)->not->toBe($connection->organisation_id);
});

it("won't link an identity that is someone else's", function (): void {
    fakeIdentityProvider(['sub' => 'taken']);
    [$user, $organisation] = member();
    $connection = SsoConnection::factory()->for($organisation)->domains()->create();
    SsoIdentity::factory()->for($connection, 'connection')->create(['subject' => 'taken']);

    $this->actingAs($user)->withSession(ssoFlow($connection, $user))->get(callback())
        ->assertRedirect(route('user-sso.index'))
        ->assertSessionHasErrors(['sso' => 'ana@harbour.example is already linked to another Meridian account.']);
});

it('lets owners set up single sign-on', function (): void {
    fakeIdentityProvider();
    [$user, $organisation] = member();

    $this->actingAs($user)->get(route('organisation.sso.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('organisation/sso')
            ->where('currentOrganisation.id', $organisation->id)
            ->where('connection', null)
            ->where('redirectUri', route('sso.callback'))
            ->where('roles', [['value' => 'viewer', 'label' => 'Viewer'], ['value' => 'operator', 'label' => 'Operator']]));

    $this->put(route('organisation.sso.update'), [
        'issuer' => IDP_ISSUER, 'client_id' => 'meridian', 'client_secret' => 'idp-secret',
        'domains' => "Harbour.example,\n harbour.example.nz harbour.example", 'auto_join' => true, 'default_role' => 'operator',
    ])->assertRedirect(route('organisation.sso.show'))->assertSessionHasNoErrors();

    $connection = $organisation->ssoConnection()->sole();
    SsoIdentity::factory()->for($connection, 'connection')->create();

    $this->get(route('organisation.sso.show'))->assertInertia(fn ($page) => $page->where('connection', [
        'issuer' => IDP_ISSUER,
        'clientId' => 'meridian',
        'domains' => 'harbour.example, harbour.example.nz',
        'autoJoin' => true,
        'defaultRole' => 'operator',
        'linked' => 1,
    ]));

    $this->put(route('organisation.sso.update'), ['issuer' => IDP_ISSUER, 'client_id' => 'meridian', 'domains' => 'harbour.example', 'default_role' => 'viewer'])->assertSessionHasNoErrors();
    expect($connection->fresh())->client_secret->toBe('idp-secret')->auto_join->toBeFalse();

    $this->delete(route('organisation.sso.destroy'))->assertRedirect(route('organisation.sso.show'));
    $this->assertModelMissing($connection);
    $this->delete(route('organisation.sso.destroy'))->assertNotFound();
});

it('checks what owners enter', function (array $input, string $field): void {
    fakeIdentityProvider();
    Http::fake(['https://93.184.216.35/*' => Http::response('', 404)]);
    [$user] = member();
    SsoConnection::factory()->domains(['taken.example'])->create();

    $this->actingAs($user)->put(route('organisation.sso.update'), [
        'issuer' => IDP_ISSUER, 'client_id' => 'meridian', 'client_secret' => 's', 'domains' => 'harbour.example', 'default_role' => 'viewer', ...$input,
    ])->assertSessionHasErrors($field);
})->with([
    'no secret at first' => [['client_secret' => ''], 'client_secret'],
    'not a URL' => [['issuer' => 'login'], 'issuer'],
    'no discovery' => [['issuer' => 'https://93.184.216.35/realms/harbour'], 'issuer'],
    'not a domain' => [['domains' => 'harbour.example, @harbour'], 'domains'],
    'no domains' => [['domains' => ''], 'domains'],
    "another organisation's domain" => [['domains' => 'harbour.example Taken.example'], 'domains'],
    'an admin role' => [['default_role' => 'admin'], 'default_role'],
]);

it('keeps single sign-on settings to owners', function (OrganisationRole $role): void {
    [$user] = member($role);

    $this->actingAs($user)->get(route('organisation.sso.show'))->assertForbidden();
    $this->put(route('organisation.sso.update'), [])->assertForbidden();
    $this->delete(route('organisation.sso.destroy'))->assertForbidden();
})->with([OrganisationRole::Admin, OrganisationRole::Operator, OrganisationRole::Viewer]);

it('limits sign-in starts and answers separately, per address', function (): void {
    for ($i = 0; $i < 10; $i++) {
        $this->post(route('sso.store'), ['email' => 'ana@gmail.example'])->assertRedirect();
    }

    $this->post(route('sso.store'), ['email' => 'ana@gmail.example'])->assertTooManyRequests();
    $this->get(callback())->assertRedirect(route('sso.create'));
});
