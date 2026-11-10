<?php

declare(strict_types=1);

use App\Models\OpcUaWrite;
use App\Models\User;

test('to array', function (): void {
    expect(array_keys(OpcUaWrite::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'twin_id', 'user_id', 'server', 'node', 'asset', 'metric', 'requested', 'setpoint', 'value', 'succeeded', 'error', 'written_at']);
});

it('belongs to a twin and the user it was written for', function (): void {
    $user = User::factory()->create();
    $write = OpcUaWrite::factory()->failed()->create(['user_id' => $user->id]);

    expect($write->twin->opcUaWrites->sole()->is($write))->toBeTrue()
        ->and($write->user?->is($user))->toBeTrue()
        ->and($write->error)->toBe('BadNotWritable');
});
