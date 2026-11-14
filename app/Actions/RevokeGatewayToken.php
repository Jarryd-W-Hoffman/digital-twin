<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Twin;

final readonly class RevokeGatewayToken
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Twin $twin): void
    {
        $twin->update([
            'gateway_token_hash' => null,
            'gateway_url' => null,
            'gateway_seen_at' => null,
            'gateway_mqtt' => null,
            'gateway_opcua' => null,
        ]);

        $this->audit->handle(AuditAction::GatewayTokenRevoked, $twin, $twin->name);
    }
}
