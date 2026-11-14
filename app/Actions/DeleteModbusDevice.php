<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\ModbusDevice;

/**
 * Removes a Modbus device from the project. Gateways stop polling it on their
 * next check-in; mappings to it stay in twins, skipped until a device of that
 * name is registered again.
 */
final readonly class DeleteModbusDevice
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(ModbusDevice $device): void
    {
        $device->delete();

        $this->audit->handle(AuditAction::ModbusDeviceDeleted, $device->project, $device->name);
    }
}
