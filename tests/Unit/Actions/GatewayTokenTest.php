<?php

declare(strict_types=1);

use App\Actions\IssueGatewayToken;
use App\Actions\RecordGatewayCheckIn;
use App\Actions\RevokeGatewayToken;
use App\Models\Twin;

it('issues a token and keeps only its hash', function (): void {
    $twin = Twin::factory()->create(['gateway_url' => 'ws://old/ws', 'gateway_seen_at' => now()]);

    $token = resolve(IssueGatewayToken::class)->handle($twin);

    expect($token)->toStartWith('mgw_')->toHaveLength(52)
        ->and($twin->refresh()->gateway_token_hash)->toBe(hash('sha256', $token))
        ->and($twin->gateway_url)->toBeNull()
        ->and($twin->gateway_seen_at)->toBeNull()
        ->and($twin->toArray())->not->toHaveKey('gateway_token_hash');
});

it('replaces the token when issued again', function (): void {
    $twin = Twin::factory()->create();
    $first = resolve(IssueGatewayToken::class)->handle($twin);
    $second = resolve(IssueGatewayToken::class)->handle($twin);

    expect($second)->not->toBe($first)
        ->and($twin->refresh()->gateway_token_hash)->toBe(hash('sha256', $second));
});

it('revokes the token', function (): void {
    $twin = Twin::factory()->create(['gateway_token_hash' => str_repeat('a', 64), 'gateway_url' => 'ws://x/ws', 'gateway_seen_at' => now(), 'gateway_mqtt' => 'connected', 'gateway_opcua' => ['PLC' => 'connected']]);

    resolve(RevokeGatewayToken::class)->handle($twin);

    expect($twin->refresh()->only(['gateway_token_hash', 'gateway_url', 'gateway_seen_at', 'gateway_mqtt', 'gateway_opcua']))
        ->toBe(['gateway_token_hash' => null, 'gateway_url' => null, 'gateway_seen_at' => null, 'gateway_mqtt' => null, 'gateway_opcua' => null]);
});

it('records a check-in, keeping only socket addresses', function (?string $url, ?string $kept): void {
    $this->freezeSecond();
    $twin = Twin::factory()->create(['gateway_token_hash' => str_repeat('a', 64)]);

    resolve(RecordGatewayCheckIn::class)->handle($twin, $url);

    expect($twin->refresh()->gateway_url)->toBe($kept)
        ->and($twin->gateway_seen_at?->equalTo(now()))->toBeTrue();
})->with([
    'ws' => ['ws://localhost:8788/ws', 'ws://localhost:8788/ws'],
    'wss' => ['wss://gw.example.com/ws', 'wss://gw.example.com/ws'],
    'http' => ['http://gw.example.com/ws', null],
    'script' => ['javascript:alert(1)', null],
    'broken' => ['ws://', null],
    'too long' => ['ws://example.com/'.str_repeat('a', 255), null],
    'missing' => [null, null],
]);

it('records whether the gateway is connected to the broker', function (?string $reported, ?string $kept): void {
    $twin = Twin::factory()->create(['gateway_token_hash' => str_repeat('a', 64)]);

    resolve(RecordGatewayCheckIn::class)->handle($twin, null, $reported);

    expect($twin->refresh()->gateway_mqtt)->toBe($kept);
})->with([
    'connected' => ['connected', 'connected'],
    'disconnected' => ['disconnected', 'disconnected'],
    'anything else' => ['<b>yes</b>', null],
    'no broker' => [null, null],
]);

it('is online only while a gateway with the current token checks in', function (): void {
    $this->freezeSecond();
    $twin = Twin::factory()->create();
    expect($twin->gatewayOnline())->toBeFalse();

    $twin->update(['gateway_token_hash' => str_repeat('a', 64), 'gateway_seen_at' => now()]);
    expect($twin->gatewayOnline())->toBeFalse();

    $twin->update(['gateway_url' => 'ws://x/ws']);
    expect($twin->gatewayOnline())->toBeTrue();

    $this->travel(Twin::GATEWAY_TIMEOUT_SECONDS + 1)->seconds();
    expect($twin->gatewayOnline())->toBeFalse();
});
