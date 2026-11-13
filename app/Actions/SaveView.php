<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\SavedView;
use App\Models\Twin;
use App\Models\User;

/**
 * Keeps how a twin looks to someone, private to them until it is shared.
 */
final readonly class SaveView
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function handle(Twin $twin, User $user, string $name, array $state): SavedView
    {
        return $twin->savedViews()->create([
            'author_id' => $user->id,
            'name' => $name,
            'state' => $state,
        ]);
    }
}
