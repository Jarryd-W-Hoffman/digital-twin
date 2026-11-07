<?php

declare(strict_types=1);

use App\Models\AssetType;
use App\Models\User;

test('to array', function (): void {
    expect(array_keys(AssetType::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'organisation_id', 'slug', 'name', 'definition', 'user_id', 'created_at', 'updated_at']);
});

it("belongs to an organisation's library and remembers who saved it", function (): void {
    $user = User::factory()->create();
    $type = AssetType::factory()->for($user)->create();

    expect($type->organisation->assetTypes->sole()->is($type))->toBeTrue()
        ->and($type->user?->is($user))->toBeTrue();
});

it('matches a twin type whatever the key order, but not a changed one', function (): void {
    $type = AssetType::factory()->create();
    $definition = $type->definition;

    expect($type->matches(array_reverse($definition, true)))->toBeTrue()
        ->and($type->matches([...$definition, 'metrics' => [array_reverse($definition['metrics'][0], true)]]))->toBeTrue()
        ->and($type->matches([...$definition, 'name' => 'Other']))->toBeFalse()
        ->and($type->matches([...$definition, 'metrics' => [...$definition['metrics'], ['key' => 'b', 'unit' => '']]]))->toBeFalse()
        ->and($type->matches('flow-meter'))->toBeFalse();
});
