<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;

/**
 * Notes that the twin's gateway is running, where browsers can reach it, and
 * whether it is connected to the MQTT broker and each OPC UA server. Only
 * ws:// and wss:// addresses are kept, since the viewer opens a socket.
 */
final readonly class RecordGatewayCheckIn
{
    private const array OPCUA_STATUSES = ['connecting', 'connected', 'reconnecting', 'failed'];

    public function handle(Twin $twin, ?string $publicUrl, ?string $mqtt = null, ?string $opcua = null): void
    {
        $scheme = is_string($publicUrl) ? parse_url($publicUrl, PHP_URL_SCHEME) : null;
        $valid = in_array($scheme, ['ws', 'wss'], true)
            && mb_strlen((string) $publicUrl) <= 255
            && filter_var(preg_replace('/^ws/', 'http', (string) $publicUrl), FILTER_VALIDATE_URL) !== false;

        $twin->update([
            'gateway_url' => $valid ? $publicUrl : null,
            'gateway_seen_at' => now(),
            'gateway_mqtt' => in_array($mqtt, ['connected', 'disconnected'], true) ? $mqtt : null,
            'gateway_opcua' => $this->opcuaStatuses($opcua),
        ]);
    }

    /**
     * Each server's status, from the gateway's JSON; anything else is dropped.
     *
     * @return array<string, string>|null
     */
    private function opcuaStatuses(?string $json): ?array
    {
        $decoded = is_string($json) && mb_strlen($json) <= 8192 ? json_decode($json, true) : null;

        if (! is_array($decoded)) {
            return null;
        }

        $statuses = [];

        foreach ($decoded as $name => $status) {
            if (is_string($name) && mb_strlen($name) <= 100 && in_array($status, self::OPCUA_STATUSES, true)) {
                $statuses[$name] = $status;
            }
        }

        return $statuses === [] ? null : $statuses;
    }
}
