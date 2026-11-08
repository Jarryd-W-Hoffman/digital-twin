<?php

declare(strict_types=1);

use App\Actions\SaveTwinVersion;
use App\Models\OpcUaServer;
use App\Models\Twin;

it("serves the latest version to the twin's gateway and records its check-in", function (): void {
    $this->freezeSecond();
    [$twin, $token] = gatewayTwin();

    $this->withToken($token)->withHeaders(['X-Gateway-Url' => 'ws://localhost:8788/ws', 'X-Gateway-Mqtt' => 'disconnected'])
        ->getJson(route('gateway.definition.show'))
        ->assertOk()
        ->assertHeader('ETag', '"v1"')
        ->assertJsonPath('twin.id', $twin->id)
        ->assertJsonPath('version', 1)
        ->assertJsonPath('definition.id', $twin->id)
        ->assertJsonPath('mqtt', ['username' => 'twin-'.$twin->id, 'topicPrefix' => $twin->project->topicPrefix()]);

    expect($twin->refresh()->gateway_url)->toBe('ws://localhost:8788/ws')
        ->and($twin->gateway_mqtt)->toBe('disconnected')
        ->and($twin->gatewayOnline())->toBeTrue();
});

it('answers 304 until a new version is saved', function (): void {
    [$twin, $token] = gatewayTwin();

    $this->withToken($token)->withHeader('If-None-Match', '"v1"')
        ->get(route('gateway.definition.show'))
        ->assertStatus(304);

    $definition = $twin->latestVersion()->firstOrFail()->definition;
    resolve(SaveTwinVersion::class)->handle($twin, null, $definition, 'Moved');

    $this->withToken($token)->withHeader('If-None-Match', '"v1"')
        ->getJson(route('gateway.definition.show'))
        ->assertOk()
        ->assertJsonPath('version', 2);
});

it('refuses a missing, unknown or revoked token', function (): void {
    [$twin, $token] = gatewayTwin();

    $this->getJson(route('gateway.definition.show'))->assertUnauthorized();
    $this->withToken('mgw_unknown')->getJson(route('gateway.definition.show'))->assertUnauthorized();

    $twin->update(['gateway_token_hash' => null]);
    $this->withToken($token)->getJson(route('gateway.definition.show'))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Missing or unknown gateway token.']);

    expect(Twin::query()->whereNotNull('gateway_seen_at')->count())->toBe(0);
});

it("sends the project's OPC UA servers, and a new ETag when they change", function (): void {
    [$twin, $token] = gatewayTwin();
    OpcUaServer::factory()->for($twin->project)->secured()->create(['name' => 'B PLC']);
    OpcUaServer::factory()->for($twin->project)->create(['name' => 'A meter']);
    OpcUaServer::factory()->create();

    $response = $this->withToken($token)->getJson(route('gateway.definition.show'))
        ->assertOk()
        ->assertJsonPath('opcua.servers.0.name', 'A meter')
        ->assertJsonPath('opcua.servers.1', [
            'name' => 'B PLC',
            'endpoint' => 'opc.tcp://plc.local:4840',
            'securityMode' => 'signAndEncrypt',
            'securityPolicy' => 'Basic256Sha256',
            'username' => 'meridian',
            'password' => 'plc-secret',
        ])
        ->assertJsonCount(2, 'opcua.servers');
    $etag = (string) $response->headers->get('ETag');
    expect($etag)->toMatch('/^"v1-[0-9a-f]{16}"$/');

    $this->withToken($token)->withHeader('If-None-Match', $etag)->get(route('gateway.definition.show'))->assertStatus(304);

    $twin->project->opcUaServers()->where('name', 'A meter')->update(['endpoint' => 'opc.tcp://meter:4840']);
    $this->withToken($token)->withHeader('If-None-Match', $etag)->getJson(route('gateway.definition.show'))
        ->assertOk()
        ->assertJsonPath('opcua.servers.0.endpoint', 'opc.tcp://meter:4840');
});

it("records each OPC UA server's status, dropping what isn't one", function (?string $header, ?array $stored): void {
    [$twin, $token] = gatewayTwin();

    $this->withToken($token)->withHeaders(array_filter(['X-Gateway-Opcua' => $header]))
        ->getJson(route('gateway.definition.show'))
        ->assertOk();

    expect($twin->refresh()->gateway_opcua)->toBe($stored);
})->with([
    'statuses' => ['{"Plant PLC":"connected","Meter":"failed"}', ['Plant PLC' => 'connected', 'Meter' => 'failed']],
    'some nonsense' => ['{"Plant PLC":"connected","Meter":"on fire","0":"connected"}', ['Plant PLC' => 'connected']],
    'nothing valid' => ['{"Meter":1}', null],
    'not JSON' => ['connected', null],
    'too long' => [json_encode(['x' => str_repeat('a', 9000)]), null],
    'no header' => [null, null],
]);
