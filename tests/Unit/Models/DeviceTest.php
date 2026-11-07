<?php

declare(strict_types=1);

use App\Models\Device;

test('to array', function (): void {
    expect(array_keys(Device::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'project_id', 'name', 'last_seen_at', 'created_at', 'updated_at']);
});

it('belongs to a project and logs in to the broker by its id', function (): void {
    $device = Device::factory()->create();

    expect($device->project->devices->sole()->is($device))->toBeTrue()
        ->and($device->mqttUsername())->toBe('device-'.$device->id);
});
