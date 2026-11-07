<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Device;

/**
 * Removes a device. The broker stops accepting it once its cached login
 * expires, within a minute.
 */
final readonly class DeleteDevice
{
    public function handle(Device $device): void
    {
        $device->delete();
    }
}
