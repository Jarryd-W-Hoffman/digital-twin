<?php

declare(strict_types=1);

use App\Actions\AddTypeToTwin;
use App\Actions\SaveTwinVersion;
use App\Actions\SaveTypeToLibrary;
use App\Models\AssetType;
use App\Support\SampleTwins;
use Illuminate\Validation\ValidationException;

it('copies a library type into the twin as a new version', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $type = AssetType::factory()->for($organisation)->create(['name' => 'Flow meter']);

    $version = resolve(AddTypeToTwin::class)->handle($twin, $user, $type);

    expect($version?->number)->toBe(2)
        ->and($version?->note)->toBe('Added type Flow meter from the library')
        ->and($version?->user_id)->toBe($user->id)
        ->and($version?->definition['types'])->toBe([$type->definition]);
});

it("replaces the twin's type of that id, and does nothing when it is already the library's", function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    resolve(SaveTwinVersion::class)->handle($twin, null, resolve(SampleTwins::class)->definition('northshore'));
    $type = resolve(SaveTypeToLibrary::class)->handle($organisation, null, [...northshoreType('water-tank'), 'name' => 'Tank']);
    $action = resolve(AddTypeToTwin::class);

    $version = $action->handle($twin, null, $type);

    expect($version?->note)->toBe('Updated type Tank from the library')
        ->and(array_column($version?->definition['types'] ?? [], 'name'))->toBe(['Tank', 'Jockey pump'])
        ->and($action->handle($twin, null, $type->refresh()))->toBeNull()
        ->and($twin->versions()->count())->toBe(3);
});

it('refuses a type the twin cannot run, saying why', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $type = resolve(SaveTypeToLibrary::class)->handle($organisation, null, northshoreType('jockey-pump'));

    expect(fn () => resolve(AddTypeToTwin::class)->handle($twin, null, $type))->toThrow(function (ValidationException $e): void {
        expect(explode("\n", $e->errors()['type'][0]))->toBe([
            'Jockey pump can’t be added to this twin:',
            'Type jockey-pump: transition to running refers to unknown asset fire-tank',
            'Type jockey-pump: transition to idle refers to unknown asset fire-tank',
        ]);
    });
    expect($twin->versions()->count())->toBe(1);
});

it('only takes types from the twin’s own organisation', function (): void {
    [, $organisation] = member();

    resolve(AddTypeToTwin::class)->handle(twinIn($organisation), null, AssetType::factory()->create());
})->throws(InvalidArgumentException::class, 'The type belongs to another organisation.');
