<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Broker usernames say who is connecting: `device-<uuid>` for a device,
 * `twin-<uuid>` for a twin's gateway.
 */
final readonly class MqttUsername
{
    /**
     * @return array{kind: 'device'|'twin', id: string}|null
     */
    public static function parse(string $username): ?array
    {
        foreach (['device', 'twin'] as $kind) {
            $id = Str::after($username, $kind.'-');

            if (str_starts_with($username, $kind.'-') && Str::isUuid($id)) {
                return ['kind' => $kind, 'id' => Str::lower($id)];
            }
        }

        return null;
    }
}
