<?php

declare(strict_types=1);

use App\Models\SsoConnection;
use App\Support\OpenIdConnect;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('finds the endpoints in the discovery document, and keeps them an hour', function (): void {
    fakeIdentityProvider();
    $oidc = resolve(OpenIdConnect::class);

    expect($oidc->discover(IDP_ISSUER))->toBe([
        'issuer' => IDP_ISSUER,
        'authorization_endpoint' => IDP_ISSUER.'/protocol/openid-connect/auth',
        'token_endpoint' => IDP_ISSUER.'/protocol/openid-connect/token',
    ]);
    $oidc->discover(IDP_ISSUER);

    Http::assertSentCount(1);
});

it('asks again when told to', function (): void {
    fakeIdentityProvider();
    $oidc = resolve(OpenIdConnect::class);

    $oidc->discover(IDP_ISSUER);
    $oidc->discover(IDP_ISSUER);
    $oidc->discover(IDP_ISSUER, fresh: true);

    Http::assertSentCount(2);
});

it('refuses providers it cannot use', function (Closure $fake, string $issuer, string $message): void {
    $fake();

    expect(fn () => resolve(OpenIdConnect::class)->discover($issuer))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no discovery document' => [fn () => Http::fake(['*' => Http::response('', 404)]), IDP_ISSUER, 'The identity provider has no OpenID Connect discovery document at '.IDP_ISSUER.'/.well-known/openid-configuration.'],
    'not JSON' => [fn () => Http::fake(['*' => Http::response('<html>')]), IDP_ISSUER, 'no OpenID Connect discovery document'],
    'unreachable' => [fn () => Http::fake(fn (): never => throw new ConnectionException('timed out')), IDP_ISSUER, "The identity provider can't be reached."],
    'another issuer' => [fn () => Http::fake(['*' => Http::response(['issuer' => 'https://elsewhere.example', 'authorization_endpoint' => 'a', 'token_endpoint' => 't'])]), IDP_ISSUER, 'calls itself https://elsewhere.example'],
    'no issuer' => [fn () => Http::fake(['*' => Http::response(['authorization_endpoint' => 'a', 'token_endpoint' => 't'])]), IDP_ISSUER, 'calls itself ?'],
    'plain http' => [fn () => Http::fake(), 'http://93.184.216.34/realms/harbour', 'The URL must start with https://.'],
    'a private network' => [fn () => Http::fake(), 'https://10.0.0.8/realms/harbour', '10.0.0.8 is on a private network. Ask whoever runs Meridian to allow private identity providers.'],
]);

it('reaches a private provider when allowed', function (): void {
    config(['meridian.sso.allow_private_issuers' => true]);
    Http::fake(['*' => Http::response(['issuer' => 'http://10.0.0.8/realms/harbour', 'authorization_endpoint' => 'http://10.0.0.8/auth', 'token_endpoint' => 'http://10.0.0.8/token'])]);

    expect(resolve(OpenIdConnect::class)->discover('http://10.0.0.8/realms/harbour')['token_endpoint'])->toBe('http://10.0.0.8/token');
});

it('sends people to sign in with PKCE, a state and a nonce', function (): void {
    fakeIdentityProvider();
    $connection = SsoConnection::factory()->make();

    $url = resolve(OpenIdConnect::class)->authorizationUrl($connection, 'https://meridian.test/sso/callback', 'state-1', 'nonce-1', 'verifier-1', 'ana@harbour.example');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith(IDP_ISSUER.'/protocol/openid-connect/auth?')
        ->and($query)->toBe([
            'response_type' => 'code',
            'client_id' => 'meridian',
            'redirect_uri' => 'https://meridian.test/sso/callback',
            'scope' => 'openid email profile',
            'state' => 'state-1',
            'nonce' => 'nonce-1',
            'code_challenge' => OpenIdConnect::challenge('verifier-1'),
            'code_challenge_method' => 'S256',
            'login_hint' => 'ana@harbour.example',
        ])
        ->and(OpenIdConnect::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

it('keeps an authorisation endpoint that has a query already', function (): void {
    Http::fake(['*' => Http::response(['issuer' => IDP_ISSUER, 'authorization_endpoint' => IDP_ISSUER.'/auth?p=signin', 'token_endpoint' => IDP_ISSUER.'/token'])]);

    expect(resolve(OpenIdConnect::class)->authorizationUrl(SsoConnection::factory()->make(), 'https://meridian.test/sso/callback', 's', 'n', 'v'))
        ->toStartWith(IDP_ISSUER.'/auth?p=signin&response_type=code')
        ->not->toContain('login_hint');
});

it('swaps the code for who signed in, as a confidential client', function (): void {
    fakeIdentityProvider(['email' => 'Ana@Harbour.example']);
    $connection = SsoConnection::factory()->make(['client_secret' => 'se:cret']);

    $claims = resolve(OpenIdConnect::class)->claims($connection, 'code-1', 'https://meridian.test/sso/callback', 'verifier-1', 'nonce-from-the-browser');

    expect($claims)->toBe(['subject' => 'ana-at-harbour', 'email' => 'ana@harbour.example', 'name' => 'Ana Ruiz']);
    Http::assertSent(fn (Request $request): bool => $request->url() === IDP_ISSUER.'/protocol/openid-connect/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('meridian:se%3Acret'))
        && $request->data() === ['grant_type' => 'authorization_code', 'code' => 'code-1', 'redirect_uri' => 'https://meridian.test/sso/callback', 'code_verifier' => 'verifier-1']);
});

it('takes the username when there is no email claim, and leaves out a missing name', function (): void {
    fakeIdentityProvider(['email' => null, 'preferred_username' => 'ana@harbour.example', 'name' => '', 'aud' => ['other', 'meridian'], 'email_verified' => null]);

    expect(resolve(OpenIdConnect::class)->claims(SsoConnection::factory()->make(), 'c', 'r', 'v', 'nonce-from-the-browser'))
        ->toBe(['subject' => 'ana-at-harbour', 'email' => 'ana@harbour.example', 'name' => null]);
});

it('refuses a sign-in it cannot trust', function (array $claims, string $message): void {
    fakeIdentityProvider($claims);

    expect(fn () => resolve(OpenIdConnect::class)->claims(SsoConnection::factory()->make(), 'c', 'r', 'v', 'nonce-from-the-browser'))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'another issuer' => [['iss' => 'https://elsewhere.example'], 'The sign-in came from another identity provider.'],
    'another client' => [['aud' => 'someone-else'], 'The sign-in was meant for another application.'],
    'expired' => [['exp' => now()->subMinutes(2)->getTimestamp()], 'The sign-in has expired. Try again.'],
    'no expiry' => [['exp' => null], 'The sign-in has expired. Try again.'],
    'another nonce' => [['nonce' => 'replayed'], "The sign-in doesn't match this browser. Try again."],
    'no nonce' => [['nonce' => null], "The sign-in doesn't match this browser. Try again."],
    'no subject' => [['sub' => ''], "The identity provider didn't say who you are."],
    'no email' => [['email' => null], "The identity provider didn't give your email address."],
    'not an email' => [['email' => 'ana'], "The identity provider didn't give your email address."],
    'unverified email' => [['email_verified' => false], "The identity provider hasn't verified your email address."],
]);

it('refuses an answer without an ID token', function (mixed $answer): void {
    Http::fake([
        IDP_ISSUER.'/.well-known/openid-configuration' => Http::response(['issuer' => IDP_ISSUER, 'authorization_endpoint' => IDP_ISSUER.'/auth', 'token_endpoint' => IDP_ISSUER.'/token']),
        IDP_ISSUER.'/token' => $answer,
    ]);

    expect(fn () => resolve(OpenIdConnect::class)->claims(SsoConnection::factory()->make(), 'c', 'r', 'v', 'n'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'an error' => fn () => Http::response(['error' => 'invalid_grant'], 400),
    'not a JWT' => fn () => Http::response(['id_token' => 'not-a-jwt']),
    'not JSON inside' => fn () => Http::response(['id_token' => 'a.'.base64_encode('nope').'.c']),
    'unreachable' => fn (): Closure => fn (): never => throw new ConnectionException('reset'),
]);
