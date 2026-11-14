<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class SaveTwinVersion
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Versions are immutable: every save appends one. The definition's id and
     * name always follow the twin record so the viewer never shows stale ones.
     *
     * @param  array<string, mixed>  $definition  An already validated definition.
     */
    public function handle(Twin $twin, ?User $user, array $definition, ?string $note = null): TwinVersion
    {
        return DB::transaction(function () use ($twin, $user, $definition, $note): TwinVersion {
            // Locking the twin serialises saves; Postgres can't lock an aggregate.
            Twin::query()->whereKey($twin->id)->lockForUpdate()->first();
            $latest = TwinVersion::query()->where('twin_id', $twin->id)->max('number');

            $version = $twin->versions()->create([
                'number' => (is_numeric($latest) ? (int) $latest : 0) + 1,
                'definition' => [...$definition, 'id' => $twin->id, 'name' => $twin->name],
                'user_id' => $user?->id,
                'note' => $note,
            ]);

            // A new twin's first version is noted as the twin being created.
            if ($version->number > 1) {
                $this->audit->handle(AuditAction::VersionSaved, $twin, __('Version :number', ['number' => $version->number]), ['note' => $note]);
            }

            return $version;
        });
    }
}
