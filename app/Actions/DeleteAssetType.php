<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AssetType;

/**
 * Takes a type out of the library. Twins hold their own copies, so they keep
 * running unchanged.
 */
final readonly class DeleteAssetType
{
    public function handle(AssetType $type): void
    {
        $type->delete();
    }
}
