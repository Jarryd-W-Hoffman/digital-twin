<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OpcUaServer;

/**
 * Removes an OPC UA server from the project. Gateways disconnect from it on
 * their next poll; mappings to it stay in twins, skipped until a server of
 * that name is registered again.
 */
final readonly class DeleteOpcUaServer
{
    public function handle(OpcUaServer $server): void
    {
        $server->delete();
    }
}
