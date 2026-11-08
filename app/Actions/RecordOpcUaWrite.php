<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OpcUaWrite;
use App\Models\Twin;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Keeps a gateway's report of a setpoint it wrote to an OPC UA node, or
 * failed to. The operator is kept while they're still a user.
 */
final readonly class RecordOpcUaWrite
{
    /**
     * @param  array{server: string, node: string, asset: string, metric: string, requested: float, setpoint: float, value: float, user: string|null, succeeded: bool, error: string|null, at: int}  $write
     */
    public function handle(Twin $twin, array $write): OpcUaWrite
    {
        return $twin->opcUaWrites()->create([
            'user_id' => $write['user'] !== null && User::query()->whereKey($write['user'])->exists() ? $write['user'] : null,
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
