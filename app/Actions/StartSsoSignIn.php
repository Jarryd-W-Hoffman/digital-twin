<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\SsoConnection;
use App\Models\User;
use App\Support\OpenIdConnect;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Begins a sign-in through an identity provider: the address to send the
 * browser to, and what to keep in its session to check the answer by.
 */
final readonly class StartSsoSignIn
{
    /**
     * The session key the flow is kept under until the provider answers.
     */
    public const string SESSION = 'sso.flow';

    public function __construct(private OpenIdConnect $oidc) {}

    /**
     * @return array{string, array{connection: string, state: string, nonce: string, verifier: string, link: string|null, then: string|null}}
     *
     * @throws ValidationException when the provider can't be reached
     */
    public function handle(SsoConnection $connection, ?string $email = null, ?User $linking = null, ?string $then = null): array
    {
        $flow = [
            'connection' => $connection->id,
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),
            'link' => $linking?->id,
            'then' => $then,
        ];

        try {
            $url = $this->oidc->authorizationUrl($connection, route('sso.callback'), $flow['state'], $flow['nonce'], $flow['verifier'], $email);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw ValidationException::withMessages([$linking instanceof User ? 'sso' : 'email' => $invalidArgumentException->getMessage()]);
        }

        return [$url, $flow];
    }
}
