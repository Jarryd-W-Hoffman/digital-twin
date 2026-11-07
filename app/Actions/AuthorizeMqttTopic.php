<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Device;
use App\Models\Project;
use App\Models\Twin;
use App\Support\MqttUsername;

/**
 * The broker's permission check, for a client that has already logged in.
 * Devices may publish below their project's topic prefix; gateways may
 * subscribe to, and receive, anything below their twin's project's prefix.
 * Nothing else is allowed, including $SYS and other projects' topics.
 */
final readonly class AuthorizeMqttTopic
{
    /** Mosquitto's access values. */
    public const int READ = 1;

    public const int WRITE = 2;

    public const int SUBSCRIBE = 4;

    public function handle(string $username, string $topic, int $access): bool
    {
        $client = MqttUsername::parse($username);

        if ($client === null) {
            return false;
        }

        [$allowed, $project] = $client['kind'] === 'device'
            ? [[self::WRITE], Device::query()->find($client['id'])?->project]
            : [[self::READ, self::SUBSCRIBE], Twin::query()->whereNotNull('gateway_token_hash')->find($client['id'])?->project];

        if (! $project instanceof Project || ! in_array($access, $allowed, true)) {
            return false;
        }

        $prefix = $project->topicPrefix();

        return str_starts_with($topic, $prefix) && mb_strlen($topic) > mb_strlen($prefix);
    }
}
