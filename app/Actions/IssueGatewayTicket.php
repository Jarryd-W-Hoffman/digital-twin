<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;
use App\Models\User;
use LogicException;

/**
 * A signed pass that lets one user's browser open a socket to the twin's
 * gateway. The gateway holds the token, the control plane holds its SHA-256
 * hash, and the hash is the HMAC key, so both can sign and check tickets
 * without the token ever being stored here.
 *
 * Format: base64url(payload JSON) "." base64url(HMAC-SHA256(that string)).
 */
final readonly class IssueGatewayTicket
{
    public const int LIFETIME_SECONDS = 12 * 3600;

    public function handle(Twin $twin, User $user, bool $canOperate, bool $canEdit): string
    {
        throw_if($twin->gateway_token_hash === null, LogicException::class, 'The twin has no gateway token.');

        $payload = $this->encode(json_encode([
            'twin' => $twin->id,
            'user' => $user->id,
            'operate' => $canOperate,
            'edit' => $canEdit,
            'exp' => now()->addSeconds(self::LIFETIME_SECONDS)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->encode(hash_hmac('sha256', $payload, $twin->gateway_token_hash, true));
    }

    private function encode(string $bytes): string
    {
        return mb_rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
