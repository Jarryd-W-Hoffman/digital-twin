<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AssetType;
use App\Models\Organisation;
use App\Models\User;

final readonly class SaveTypeToLibrary
{
    /**
     * Keeps a type in the organisation's library, replacing the one with the
     * same id. Twins that took a copy keep theirs until they take it again.
     *
     * @param  array<string, mixed>  $type  An already validated type.
     */
    public function handle(Organisation $organisation, ?User $user, array $type): AssetType
    {
        $slug = $type['id'];
        $name = $type['name'];
        assert(is_string($slug) && is_string($name));

        return $organisation->assetTypes()->updateOrCreate(['slug' => $slug], [
            'name' => $name,
            'definition' => $type,
            'user_id' => $user?->id,
        ]);
    }
}
