<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;

/**
 * Notes that the twin's gateway is running, and where browsers can reach it.
 * Only ws:// and wss:// addresses are kept, since the viewer opens a socket.
 */
final readonly class RecordGatewayCheckIn
{
    public function handle(Twin $twin, ?string $publicUrl): void
    {
        $scheme = is_string($publicUrl) ? parse_url($publicUrl, PHP_URL_SCHEME) : null;
        $valid = in_array($scheme, ['ws', 'wss'], true)
            && mb_strlen((string) $publicUrl) <= 255
            && filter_var(preg_replace('/^ws/', 'http', (string) $publicUrl), FILTER_VALIDATE_URL) !== false;

        $twin->update([
            'gateway_url' => $valid ? $publicUrl : null,
            'gateway_seen_at' => now(),
        ]);
    }
}
