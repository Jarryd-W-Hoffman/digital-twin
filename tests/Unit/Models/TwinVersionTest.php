<?php

declare(strict_types=1);

use App\Models\TwinVersion;
use App\Models\User;

test('to array', function (): void {
    expect(array_keys(TwinVersion::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'twin_id', 'number', 'definition', 'user_id', 'note', 'created_at', 'updated_at']);
});

it('records who saved it', function (): void {
    $user = User::factory()->create();
    $version = TwinVersion::factory()->create(['user_id' => $user->id]);

    expect($version->user?->is($user))->toBeTrue()
        ->and($version->twin->versions->sole()->is($version))->toBeTrue()
        ->and($version->definition)->toHaveKey('id', 'harbour-depot');
});
