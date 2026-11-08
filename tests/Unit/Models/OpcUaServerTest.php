<?php

declare(strict_types=1);

use App\Models\OpcUaServer;

test('to array', function (): void {
    expect(array_keys(OpcUaServer::factory()->secured()->create()->refresh()->toArray()))
        ->toBe(['id', 'project_id', 'name', 'endpoint', 'security_mode', 'security_policy', 'username', 'created_at', 'updated_at']);
});

it('belongs to a project and tells gateways how to connect', function (): void {
    $server = OpcUaServer::factory()->secured()->create(['name' => 'Plant PLC']);

    expect($server->project->opcUaServers->sole()->is($server))->toBeTrue()
        ->and($server->forGateway())->toBe([
            'name' => 'Plant PLC',
            'endpoint' => 'opc.tcp://plc.local:4840',
            'securityMode' => 'signAndEncrypt',
            'securityPolicy' => 'Basic256Sha256',
            'username' => 'meridian',
            'password' => 'plc-secret',
        ]);
});
