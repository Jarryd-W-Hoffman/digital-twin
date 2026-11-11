<?php

declare(strict_types=1);

use App\Enums\AlertSeverity;
use App\Models\Alert;

/**
 * @return array<string, mixed>
 */
function openAlert(array $overrides = []): array
{
    return [
        'rule' => 'warm',
        'asset' => 'store',
        'severity' => 'critical',
        'message' => 'Seafood store is above its temperature limit',
        'value' => 1.5,
        ...$overrides,
    ];
}

it('raises what the gateway reports open, keeps it, and clears it once it is not', function (): void {
    $this->freezeSecond();
    [$twin, $token] = gatewayTwin();

    $this->withToken($token)
        ->postJson(route('gateway.alerts.store'), ['open' => [openAlert(), openAlert(['rule' => 'tripped', 'asset' => 'plant', 'severity' => 'warning', 'value' => 4])]])
        ->assertOk()
        ->assertExactJson(['raised' => 2, 'cleared' => 0]);

    $warm = Alert::query()->where('rule', 'warm')->sole();
    expect($warm->twin_id)->toBe($twin->id)
        ->and($warm->severity)->toBe(AlertSeverity::Critical)
        ->and($warm->message)->toBe('Seafood store is above its temperature limit')
        ->and($warm->value)->toBe(1.5)
        ->and($warm->raised_at->equalTo(now()))->toBeTrue()
        ->and($warm->cleared_at)->toBeNull();

    $this->travel(1)->minutes();
    $this->withToken($token)
        ->postJson(route('gateway.alerts.store'), ['open' => [openAlert(['value' => 3])]])
        ->assertExactJson(['raised' => 0, 'cleared' => 1]);

    expect(Alert::query()->count())->toBe(2)
        ->and($warm->refresh()->value)->toBe(1.5)
        ->and($warm->cleared_at)->toBeNull()
        ->and(Alert::query()->where('rule', 'tripped')->sole()->cleared_at?->equalTo(now()))->toBeTrue();

    $this->withToken($token)
        ->postJson(route('gateway.alerts.store'), ['open' => []])
        ->assertExactJson(['raised' => 0, 'cleared' => 1]);
    $this->withToken($token)
        ->postJson(route('gateway.alerts.store'), ['open' => [openAlert()]])
        ->assertExactJson(['raised' => 1, 'cleared' => 0]);

    expect(Alert::query()->where('rule', 'warm')->count())->toBe(2)
        ->and(Alert::query()->whereNull('cleared_at')->count())->toBe(1);
});

it("leaves other twins' alerts alone", function (): void {
    [, $token] = gatewayTwin();
    $other = Alert::factory()->create();

    $this->withToken($token)->postJson(route('gateway.alerts.store'), ['open' => []])->assertOk();

    expect($other->refresh()->cleared_at)->toBeNull();
});

it('takes a repeated rule and asset once', function (): void {
    [, $token] = gatewayTwin();

    $this->withToken($token)
        ->postJson(route('gateway.alerts.store'), ['open' => [openAlert(), openAlert(['value' => 2])]])
        ->assertExactJson(['raised' => 1, 'cleared' => 0]);
});

it('refuses a report it cannot use', function (array $body, string $error): void {
    [, $token] = gatewayTwin();

    $this->withToken($token)->postJson(route('gateway.alerts.store'), $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    expect(Alert::query()->count())->toBe(0);
})->with([
    'no list' => [[], 'open'],
    'not a list' => [['open' => ['a' => openAlert()]], 'open'],
    'too many' => [['open' => array_fill(0, 501, openAlert())], 'open'],
    'unknown severity' => [['open' => [openAlert(['severity' => 'apocalyptic'])]], 'open.0.severity'],
    'no rule' => [['open' => [openAlert(['rule' => ''])]], 'open.0.rule'],
    'long message' => [['open' => [openAlert(['message' => str_repeat('x', 501)])]], 'open.0.message'],
    'no value' => [['open' => [openAlert(['value' => 'warm'])]], 'open.0.value'],
    'extra keys' => [['open' => [openAlert(['note' => 'x'])]], 'open.0'],
]);

it("needs the twin's gateway token", function (): void {
    gatewayTwin();

    $this->withToken('mgw_wrong')->postJson(route('gateway.alerts.store'), ['open' => []])->assertUnauthorized();
});
