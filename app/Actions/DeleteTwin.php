<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteTwin
{
    public function handle(Twin $twin): void
    {
        $twin->delete();

        Storage::disk(config()->string('meridian.assets_disk'))->deleteDirectory($twin->storagePrefix());
    }
}
