<?php

declare(strict_types=1);

use App\Models\Twin;
use App\Models\TwinAsset;

test('to array', function (): void {
    expect(array_keys(Twin::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'organisation_id', 'name', 'description', 'created_at', 'updated_at']);
});

it('tracks its versions, latest first', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $twin->versions()->create(['number' => 2, 'definition' => ['id' => 'x']]);

    expect($twin->organisation->is($organisation))->toBeTrue()
        ->and($twin->versions)->toHaveCount(2)
        ->and($twin->latestVersion?->number)->toBe(2)
        ->and($twin->storagePrefix())->toBe('twins/'.$twin->id);
});

it('owns its uploaded assets', function (): void {
    $asset = TwinAsset::factory()->create();

    expect($asset->twin->assets->sole()->is($asset))->toBeTrue();
});
