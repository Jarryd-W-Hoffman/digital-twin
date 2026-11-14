<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SsoConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * The OpenID Connect authorisation code flow with PKCE, as a confidential
 * client. Meridian swaps the code for tokens with the provider directly,
 * over TLS to an address it has checked, so it takes the ID token's claims
 * from that answer without checking its signature, as OpenID Connect Core
 * 3.1.3.7 allows; it still checks who issued it, for whom, until when, and
 * the nonce.
 */
final readonly class OpenIdConnect
{
    public function __construct(private WebhookGuard $guard) {}

    /**
     * PKCE's S256 challenge for a verifier.
     */
    public static function challenge(string $verifier): string
    {
        return mb_rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * The provider's endpoints, from its discovery document (kept an hour).
     *
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string}
     *
     * @throws InvalidArgumentException with the reason, for people to read
     */
    public function discover(string $issuer, bool $fresh = false): array
    {
        $key = 'sso.discovery.'.hash('sha256', $issuer);
        if ($fresh) {
            Cache::forget($key);
        }

        /** @var array{issuer: string, authorization_endpoint: string, token_endpoint: string} */
        return Cache::remember($key, now()->addHour(), function () use ($issuer): array {
            $url = mb_rtrim($issuer, '/').'/.well-known/openid-configuration';

            try {
                $response = $this->request($url)->acceptJson()->get($url);
            } catch (ConnectionException) {
                throw new InvalidArgumentException(__("The identity provider can't be reached."));
            }

            $document = $response->successful() ? $response->json() : null;
            $authorize = is_array($document) ? ($document['authorization_endpoint'] ?? null) : null;
            $token = is_array($document) ? ($document['token_endpoint'] ?? null) : null;

            throw_unless(is_string($authorize) && is_string($token), InvalidArgumentException::class, __('The identity provider has no OpenID Connect discovery document at :url.', ['url' => $url]));
            throw_unless(($document['issuer'] ?? null) === $issuer, InvalidArgumentException::class, __('The identity provider calls itself :issuer; use that as the issuer.', ['issuer' => is_string($document['issuer'] ?? null) ? $document['issuer'] : '?']));

            return ['issuer' => $issuer, 'authorization_endpoint' => $authorize, 'token_endpoint' => $token];
        });
    }

    /**
     * Where to send someone to sign in.
     */
    public function authorizationUrl(SsoConnection $connection, string $redirectUri, string $state, string $nonce, string $verifier, ?string $email = null): string
    {
        $endpoint = $this->discover($connection->issuer)['authorization_endpoint'];

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => $connection->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
            'login_hint' => $email,
        ]));
    }

    /**
     * Swaps the code the provider sent back for who signed in.
     *
     * @return array{subject: string, email: string, name: string|null}
     *
     * @throws InvalidArgumentException with the reason, for people to read
     */
    public function claims(SsoConnection $connection, string $code, string $redirectUri, string $verifier, string $nonce): array
    {
        $endpoint = $this->discover($connection->issuer)['token_endpoint'];

        try {
            $response = $this->request($endpoint)
                ->asForm()
                ->acceptJson()
                ->withBasicAuth(rawurlencode($connection->client_id), rawurlencode($connection->client_secret))
                ->post($endpoint, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $verifier,
                ]);
        } catch (ConnectionException) {
            throw new InvalidArgumentException(__("The identity provider can't be reached."));
        }

        $token = $response->successful() ? $response->json('id_token') : null;
        throw_unless(is_string($token), InvalidArgumentException::class, __("The identity provider didn't sign you in."));

        $claims = $this->payload($token);
        $audience = (array) ($claims['aud'] ?? []);

        throw_unless(($claims['iss'] ?? null) === $connection->issuer, InvalidArgumentException::class, __('The sign-in came from another identity provider.'));
        throw_unless(in_array($connection->client_id, $audience, true), InvalidArgumentException::class, __('The sign-in was meant for another application.'));
        throw_unless(is_numeric($claims['exp'] ?? null) && (int) $claims['exp'] > now()->getTimestamp() - 60, InvalidArgumentException::class, __('The sign-in has expired. Try again.'));
        throw_unless(is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']), InvalidArgumentException::class, __("The sign-in doesn't match this browser. Try again."));
        throw_unless(is_string($claims['sub'] ?? null) && $claims['sub'] !== '', InvalidArgumentException::class, __("The identity provider didn't say who you are."));

        $email = $claims['email'] ?? $claims['preferred_username'] ?? null;
        throw_unless(is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false, InvalidArgumentException::class, __("The identity provider didn't give your email address. Ask your administrator to send the email claim."));
        throw_if(($claims['email_verified'] ?? true) === false, InvalidArgumentException::class, __("The identity provider hasn't verified your email address."));

        return [
            'subject' => $claims['sub'],
            'email' => mb_strtolower($email),
            'name' => is_string($claims['name'] ?? null) && $claims['name'] !== '' ? $claims['name'] : null,
        ];
    }

    /**
     * A JWT's claims.
     *
     * @return array<string, mixed>
     */
    private function payload(string $token): array
    {
        $parts = explode('.', $token);
        $json = count($parts) === 3 ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;
        $claims = is_string($json) ? json_decode($json, true) : null;

        throw_unless(is_array($claims), InvalidArgumentException::class, __("The identity provider didn't sign you in."));

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * A short request that doesn't follow redirects, connecting to the
     * address that was checked.
     */
    private function request(string $url): PendingRequest
    {
        $address = $this->guard->address($url, config()->boolean('meridian.sso.allow_private_issuers'), __(':host is on a private network. Ask whoever runs Meridian to allow private identity providers.'));

        return WebhookGuard::pin(Http::timeout(10)->connectTimeout(5)->withoutRedirecting(), $url, $address);
    }
}
