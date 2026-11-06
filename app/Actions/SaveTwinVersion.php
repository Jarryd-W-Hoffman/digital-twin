<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class SaveTwinVersion
{
    /**
     * Versions are immutable: every save appends one. The definition's id and
     * name always follow the twin record so the viewer never shows stale ones.
     *
     * @param  array<string, mixed>  $definition  An already validated definition.
     */
    public function handle(Twin $twin, ?User $user, array $definition, ?string $note = null): TwinVersion
    {
        return DB::transaction(function () use ($twin, $user, $definition, $note): TwinVersion {
            $latest = TwinVersion::query()->where('twin_id', $twin->id)->lockForUpdate()->max('number');

            return $twin->versions()->create([
                'number' => (is_numeric($latest) ? (int) $latest : 0) + 1,
                'definition' => [...$definition, 'id' => $twin->id, 'name' => $twin->name],
                'user_id' => $user?->id,
                'note' => $note,
            ]);
        });
    }
}
