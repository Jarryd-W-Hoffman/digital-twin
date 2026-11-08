<?php

declare(strict_types=1);

use App\Models\Twin;

test('to array', function (): void {
    expect(array_keys(Twin::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'organisation_id', 'name', 'description', 'created_at', 'updated_at', 'gateway_url', 'gateway_seen_at', 'project_id', 'gateway_mqtt', 'gateway_opcua']);
});

it('never serialises its gateway token hash', function (): void {
    $twin = Twin::factory()->create(['gateway_token_hash' => hash('sha256', 'mgw_secret')]);

    expect($twin->refresh()->toArray())->not->toHaveKey('gateway_token_hash');
});

it('tracks its versions, latest first', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $twin->versions()->create(['number' => 2, 'definition' => ['id' => 'x']]);

    expect($twin->organisation->is($organisation))->toBeTrue()
        ->and($twin->versions)->toHaveCount(2)
        ->and($twin->latestVersion?->number)->toBe(2);
});

it('belongs to a project in its own organisation', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);

    expect($twin->project->organisation->is($organisation))->toBeTrue()
        ->and($twin->project->twins->sole()->is($twin))->toBeTrue();
});

it('logs in to the broker as its gateway', function (): void {
    $twin = Twin::factory()->create();

    expect($twin->mqttUsername())->toBe('twin-'.$twin->id);
});
