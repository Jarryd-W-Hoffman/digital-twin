<?php

declare(strict_types=1);

use App\Actions\SaveTwinVersion;
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
