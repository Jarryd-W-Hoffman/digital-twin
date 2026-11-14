<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Device;

/**
 * Removes a device. The broker stops accepting it once its cached login
 * expires, within a minute.
 */
final readonly class DeleteDevice
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Device $device): void
    {
        $device->delete();

        $this->audit->handle(AuditAction::DeviceDeleted, $device->project, $device->name);
    }
}
