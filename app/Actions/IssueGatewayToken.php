<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;
use Illuminate\Support\Str;

/**
 * Gives a twin a new gateway token, replacing any earlier one. Only its hash
 * is stored, so the token is returned once and cannot be shown again.
 */
final readonly class IssueGatewayToken
{
    public function handle(Twin $twin): string
    {
        $token = 'mgw_'.Str::random(48);

        $twin->update([
            'gateway_token_hash' => hash('sha256', $token),
            'gateway_url' => null,
            'gateway_seen_at' => null,
            'gateway_mqtt' => null,
            'gateway_opcua' => null,
        ]);

        return $token;
    }
}
