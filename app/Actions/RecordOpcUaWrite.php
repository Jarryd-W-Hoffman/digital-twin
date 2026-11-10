<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OpcUaWrite;
use App\Models\Twin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Keeps a gateway's report of a setpoint it wrote to an OPC UA node, or
 * failed to. The operator is kept while they're still a user; an id that
 * isn't a UUID can't be one, and isn't looked up (Postgres refuses it).
 */
final readonly class RecordOpcUaWrite
{
    /**
     * @param  array{server: string, node: string, asset: string, metric: string, requested: float, setpoint: float, value: float, user: string|null, succeeded: bool, error: string|null, at: int}  $write
     */
    public function handle(Twin $twin, array $write): OpcUaWrite
    {
        $user = $write['user'];

        return $twin->opcUaWrites()->create([
            'user_id' => $user !== null && Str::isUuid($user) && User::query()->whereKey($user)->exists() ? $user : null,
            'server' => $write['server'],
            'node' => $write['node'],
            'asset' => $write['asset'],
            'metric' => $write['metric'],
            'requested' => $write['requested'],
            'setpoint' => $write['setpoint'],
            'value' => $write['value'],
            'succeeded' => $write['succeeded'],
            'error' => $write['succeeded'] ? null : $write['error'],
            'written_at' => CarbonImmutable::createFromTimestampMs($write['at']),
        ]);
    }
}
