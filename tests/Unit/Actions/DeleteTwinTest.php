<?php

declare(strict_types=1);

use App\Actions\DeleteTwin;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\Storage;

it('deletes the twin and leaves the project library alone', function (): void {
    Storage::fake('local');
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create();
    Storage::disk('local')->put($asset->directory.'/model.glb', 'x');

    resolve(DeleteTwin::class)->handle($twin);

    expect($twin->exists)->toBeFalse()
        ->and($asset->fresh())->not->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([$asset->directory.'/model.glb']);
});
