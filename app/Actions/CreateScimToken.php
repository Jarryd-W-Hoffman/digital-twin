<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\SsoConnection;
use Illuminate\Support\Str;

final readonly class CreateScimToken
{
    /**
     * Shown once; only its hash is kept.
     */
    public const string PREFIX = 'mrs_';

    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Makes the token the identity provider provisions people with,
     * replacing any it had.
     */
    public function handle(SsoConnection $connection): string
    {
        $token = self::PREFIX.Str::random(48);
        $replaced = $connection->scim_token_hash !== null;

        $connection->forceFill([
            'scim_token_hash' => hash('sha256', $token),
            'scim_token_hint' => mb_substr($token, -4),
            'scim_token_used_at' => null,
        ])->save();

        $this->audit->handle(AuditAction::ScimTokenCreated, $connection->organisation, null, $replaced ? ['replaced' => 'yes'] : []);

        return $token;
    }
}
