<?php

declare(strict_types=1);

use App\Models\Reading;

it("stores a gateway's readings for its twin's history", function (): void {
    [$twin, $token] = gatewayTwin();
    $at = now()->getTimestampMs();

    $this->withToken($token)->postJson(route('gateway.readings.store'), ['readings' => [
        ['asset' => 'store', 'metric' => 'tempC', 'value' => -18.5, 'at' => $at],
        ['asset' => 'plant', 'metric' => 'powerKw', 'value' => '42', 'at' => $at - 1500],
    ]])->assertAccepted()->assertExactJson(['stored' => 2]);

    $readings = Reading::query()->oldest('recorded_at')->get();
    expect($readings)->toHaveCount(2)
        ->and($readings[0]->twin_id)->toBe($twin->id)
        ->and($readings[0]->asset)->toBe('plant')
        ->and($readings[0]->value)->toBe(42.0)
        ->and($readings[0]->recorded_at->getTimestampMs())->toBe($at - 1500)
        ->and($readings[1]->recorded_at->getTimestampMs())->toBe($at);
});

it('takes readings only for assets in the twin, recently measured', function (): void {
    [, $token] = gatewayTwin();
    $now = now()->getTimestampMs();

    $this->withToken($token)->postJson(route('gateway.readings.store'), ['readings' => [
        ['asset' => 'nowhere', 'metric' => 'Temp', 'value' => 'x', 'at' => $now - 8 * 86_400_000],
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 1, 'at' => $now + 3_600_000],
    ]])->assertJsonValidationErrors(['readings.0.asset', 'readings.0.metric', 'readings.0.value', 'readings.0.at', 'readings.1.at']);

    $this->withToken($token)->postJson(route('gateway.readings.store'), ['readings' => []])->assertJsonValidationErrors('readings');
    expect(Reading::query()->count())->toBe(0);
});

it('turns away requests without a known gateway token', function (?string $token): void {
    gatewayTwin();

    $request = $token === null ? $this : $this->withToken($token);
    $request->postJson(route('gateway.readings.store'), ['readings' => []])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Missing or unknown gateway token.']);
})->with([null, 'mgw_wrong']);
