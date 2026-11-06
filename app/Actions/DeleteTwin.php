<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;

final readonly class DeleteTwin
{
    /**
     * Deletes the twin and its versions. Its layers' files belong to the
     * project's library, so they stay for the project's other twins.
     */
    public function handle(Twin $twin): void
    {
        $twin->delete();
    }
}
