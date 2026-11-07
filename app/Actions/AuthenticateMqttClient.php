<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Device;
use App\Models\Twin;
use App\Support\MqttUsername;

/**
 * The broker's login check. Devices log in as `device-<id>` with their
 * password; gateways as `twin-<id>` with the twin's gateway token.
 */
final readonly class AuthenticateMqttClient
{
    public function handle(string $username, string $password): bool
    {
        $client = MqttUsername::parse($username);
        $hash = hash('sha256', $password);

        if ($client === null) {
            return false;
        }

        if ($client['kind'] === 'twin') {
            $hashed = Twin::query()->whereKey($client['id'])->value('gateway_token_hash');

            return is_string($hashed) && hash_equals($hashed, $hash);
        }

        $device = Device::query()->find($client['id']);

        if (! $device instanceof Device || ! is_string($device->password_hash) || ! hash_equals($device->password_hash, $hash)) {
            return false;
        }

        $device->update(['last_seen_at' => now()]);

        return true;
    }
}
