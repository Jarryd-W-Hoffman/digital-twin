<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteTwin
{
    /**
     * Deletes the twin with its versions and issues, then the issues'
     * photos. Its layers' files belong to the project's library, so they
     * stay for the project's other twins.
     */
    public function handle(Twin $twin): void
    {
        $directory = $twin->storagePrefix();

        $twin->delete();

        Storage::disk(config()->string('meridian.assets_disk'))->deleteDirectory($directory);
    }
}
