<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OpcUaSecurityMode;
use App\Enums\OpcUaSecurityPolicy;
use App\Models\OpcUaServer;
use App\Models\Project;

/**
 * Registers an OPC UA server with the project, so its twins can map its
 * nodes and their gateways connect to it.
 */
final readonly class CreateOpcUaServer
{
    public function handle(
        Project $project,
        string $name,
        string $endpoint,
        OpcUaSecurityMode $mode = OpcUaSecurityMode::None,
        OpcUaSecurityPolicy $policy = OpcUaSecurityPolicy::None,
        ?string $username = null,
        ?string $password = null,
    ): OpcUaServer {
        return $project->opcUaServers()->create([
            'name' => $name,
            'endpoint' => $endpoint,
            'security_mode' => $mode,
            'security_policy' => $policy,
            'username' => $username,
            'password' => $username === null ? null : $password,
        ]);
    }
}
