<?php

declare(strict_types=1);

use App\Actions\AddTypeToTwin;
use App\Actions\DeleteAssetType;
use App\Models\AssetType;

it('deletes a library type and leaves the twins that copied it alone', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $type = AssetType::factory()->for($organisation)->create();
    resolve(AddTypeToTwin::class)->handle($twin, null, $type);

    resolve(DeleteAssetType::class)->handle($type);

    expect($type->fresh())->toBeNull()
        ->and($twin->latestVersion()->sole()->definition['types'][0]['id'])->toBe($type->slug);
});
