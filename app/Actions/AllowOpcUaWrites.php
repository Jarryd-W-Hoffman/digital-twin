<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;

/**
 * Lets operators' setpoints through to the OPC UA nodes a twin maps for
 * writing, or stops them. Off until an editor turns it on; the twin's
 * gateway follows within a poll.
 */
final readonly class AllowOpcUaWrites
{
    public function handle(Twin $twin, bool $allowed): void
    {
        $twin->update(['opcua_writes' => $allowed]);
    }
}
