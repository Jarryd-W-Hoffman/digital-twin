<?php

declare(strict_types=1);

use App\Actions\SaveTwinVersion;
use App\Models\Twin;

it('appends numbered versions and keeps the definition id and name in step with the twin', function (): void {
    [$user, $organisation] = member();
    $twin = Twin::factory()->for($organisation)->create(['name' => 'Quay 4']);
    $action = resolve(SaveTwinVersion::class);

    $first = $action->handle($twin, $user, ['id' => 'other', 'name' => 'Other', 'assets' => []], 'First');
    $second = $action->handle($twin, null, $first->definition);

    expect($first->number)->toBe(1)
        ->and($first->note)->toBe('First')
        ->and($first->user_id)->toBe($user->id)
        ->and($first->definition)->toMatchArray(['id' => $twin->id, 'name' => 'Quay 4'])
        ->and($second->number)->toBe(2)
        ->and($second->user_id)->toBeNull();
});
