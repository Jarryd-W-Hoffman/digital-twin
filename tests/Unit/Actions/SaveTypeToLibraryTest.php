<?php

declare(strict_types=1);

use App\Actions\SaveTypeToLibrary;
use App\Models\AssetType;
use App\Models\Organisation;

it('keeps a type in the library, replacing one with the same id', function (): void {
    [$user, $organisation] = member();
    $action = resolve(SaveTypeToLibrary::class);

    $first = $action->handle($organisation, null, northshoreType('water-tank'));
    $second = $action->handle($organisation, $user, [...northshoreType('water-tank'), 'name' => 'Tank']);

    expect($second->is($first))->toBeTrue()
        ->and($second->slug)->toBe('water-tank')
        ->and($second->name)->toBe('Tank')
        ->and($second->definition['name'])->toBe('Tank')
        ->and($second->user_id)->toBe($user->id)
        ->and($organisation->assetTypes()->count())->toBe(1);
});

it('keeps each organisation’s library apart', function (): void {
    $action = resolve(SaveTypeToLibrary::class);
    $action->handle(Organisation::factory()->create(), null, northshoreType('water-tank'));
    $action->handle(Organisation::factory()->create(), null, northshoreType('water-tank'));

    expect(AssetType::query()->count())->toBe(2);
});
