<?php

declare(strict_types=1);

use App\Models\OpcUaWrite;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @return array<string, mixed>
 */
function opcUaWriteReport(array $overrides = []): array
{
    return [
        'server' => 'Plant PLC',
        'node' => 'ns=2;s=Jockey.Start',
        'asset' => 'jockey',
        'metric' => 'startKpa',
        'requested' => 9000,
        'setpoint' => 720,
        'value' => 7.2,
        'user' => null,
        'succeeded' => true,
        'error' => null,
        'at' => now()->getTimestampMs(),
        ...$overrides,
    ];
}

it("records a write the twin's gateway made for an operator", function (): void {
    $this->freezeSecond();
    [$twin, $token] = gatewayTwin();
    $operator = User::factory()->create();

    $response = $this->withToken($token)
        ->postJson(route('gateway.opcua-writes.store'), opcUaWriteReport(['user' => $operator->id]))
        ->assertCreated();

    $write = OpcUaWrite::query()->sole();
    $response->assertExactJson(['id' => $write->id]);
    expect($write->twin->is($twin))->toBeTrue()
        ->and($write->user?->is($operator))->toBeTrue()
        ->and($write->only(['server', 'node', 'asset', 'metric', 'requested', 'setpoint', 'value', 'succeeded', 'error']))->toBe([
            'server' => 'Plant PLC',
            'node' => 'ns=2;s=Jockey.Start',
            'asset' => 'jockey',
            'metric' => 'startKpa',
            'requested' => 9000.0,
            'setpoint' => 720.0,
            'value' => 7.2,
            'succeeded' => true,
            'error' => null,
        ])
        ->and($write->written_at->getTimestampMs())->toBe(now()->getTimestampMs());
});

it('keeps a failed write with its reason, and drops users it does not know', function (): void {
    [, $token] = gatewayTwin();

    $this->withToken($token)
        ->postJson(route('gateway.opcua-writes.store'), opcUaWriteReport(['user' => 'someone-gone', 'succeeded' => false, 'error' => str_repeat('BadNotWritable ', 50)]))
        ->assertCreated();

    $write = OpcUaWrite::query()->sole();
    expect($write->user_id)->toBeNull()
        ->and($write->succeeded)->toBeFalse()
        ->and($write->error)->toHaveLength(500)->toStartWith('BadNotWritable');
});

it('drops a user id that is a UUID of nobody', function (): void {
    [, $token] = gatewayTwin();

    $this->withToken($token)
        ->postJson(route('gateway.opcua-writes.store'), opcUaWriteReport(['user' => (string) Str::uuid()]))
        ->assertCreated();

    expect(OpcUaWrite::query()->sole()->user_id)->toBeNull();
});

it('drops the reason from a write that succeeded', function (): void {
    [, $token] = gatewayTwin();

    $this->withToken($token)->postJson(route('gateway.opcua-writes.store'), opcUaWriteReport(['error' => 'stale']))->assertCreated();

    expect(OpcUaWrite::query()->sole()->error)->toBeNull();
});

it('refuses malformed reports and unknown gateways', function (array $overrides, string $field): void {
    [, $token] = gatewayTwin();

    $this->withToken($token)->postJson(route('gateway.opcua-writes.store'), opcUaWriteReport($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(OpcUaWrite::query()->count())->toBe(0);
})->with([
    'no server' => [['server' => ''], 'server'],
    'a bad metric' => [['metric' => 'Start KPa'], 'metric'],
    'a value that is not a number' => [['value' => 'high'], 'value'],
    'from two days ago' => [['at' => now()->subDays(2)->getTimestampMs()], 'at'],
    'no outcome' => [['succeeded' => null], 'succeeded'],
]);

it('answers 401 without its token', function (): void {
    $this->postJson(route('gateway.opcua-writes.store'), opcUaWriteReport())->assertUnauthorized();
});
