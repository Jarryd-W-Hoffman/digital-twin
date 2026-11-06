<?php

declare(strict_types=1);

use App\Actions\DeleteTwin;
use Illuminate\Support\Facades\Storage;

it('deletes the twin and its files', function (): void {
    Storage::fake('local');
    [, $organisation] = member();
    $twin = twinIn($organisation);
    Storage::disk('local')->put($twin->storagePrefix().'/assets/a/model.glb', 'x');

    resolve(DeleteTwin::class)->handle($twin);

    expect($twin->exists)->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
