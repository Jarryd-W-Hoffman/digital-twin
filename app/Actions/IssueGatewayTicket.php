<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;
use App\Models\TwinShareLink;
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

    /**
     * A share link's ticket: short, read only, and ending the connection
     * when it expires, so a revoked link stops following the twin.
     */
    public const int SHARE_LIFETIME_SECONDS = 600;

    public function handle(Twin $twin, User $user, bool $canOperate, bool $canEdit): string
    {
        return $this->sign($twin, [
            'twin' => $twin->id,
            'user' => $user->id,
            'operate' => $canOperate,
            'edit' => $canEdit,
            'exp' => now()->addSeconds(self::LIFETIME_SECONDS)->getTimestamp(),
        ]);
    }

    public function forShare(TwinShareLink $link): string
    {
        return $this->sign($link->twin, [
            'twin' => $link->twin_id,
            'user' => 'share:'.$link->id,
            'operate' => false,
            'edit' => false,
            'exp' => now()->addSeconds(self::SHARE_LIFETIME_SECONDS)->getTimestamp(),
            'close' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function sign(Twin $twin, array $claims): string
    {
        throw_if($twin->gateway_token_hash === null, LogicException::class, 'The twin has no gateway token.');

        $payload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->encode(hash_hmac('sha256', $payload, $twin->gateway_token_hash, true));
    }

    private function encode(string $bytes): string
    {
        return mb_rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
