<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\SsoConnection;

final readonly class RevokeScimToken
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Stops the identity provider provisioning anyone. Who it provisioned stays.
     */
    public function handle(SsoConnection $connection): void
    {
        $connection->forceFill(['scim_token_hash' => null, 'scim_token_hint' => null, 'scim_token_used_at' => null])->save();

        $this->audit->handle(AuditAction::ScimTokenRevoked, $connection->organisation);
    }
}
