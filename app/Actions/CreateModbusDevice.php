<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\ModbusDevice;
use App\Models\Project;

/**
 * Registers a Modbus TCP device with the project, so its twins can map its
 * registers and their gateways poll it.
 */
final readonly class CreateModbusDevice
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Project $project, string $name, string $host, int $port = 502, int $unitId = 1): ModbusDevice
    {
        $device = $project->modbusDevices()->create([
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'unit_id' => $unitId,
        ]);

        $this->audit->handle(AuditAction::ModbusDeviceAdded, $project, $name, ['address' => $host.':'.$port, 'unit' => $unitId]);

        return $device;
    }
}
