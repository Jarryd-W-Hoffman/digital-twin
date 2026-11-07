<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;

final readonly class RevokeGatewayToken
{
    public function handle(Twin $twin): void
    {
        $twin->update([
            'gateway_token_hash' => null,
            'gateway_url' => null,
            'gateway_seen_at' => null,
            'gateway_mqtt' => null,
        ]);
    }
}
