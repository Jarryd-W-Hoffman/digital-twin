<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Twin;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteTwin
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Deletes the twin with its versions and issues, then the issues'
     * photos. Its layers' files belong to the project's library, so they
     * stay for the project's other twins.
     */
    public function handle(Twin $twin): void
    {
        $directory = $twin->storagePrefix();

        $twin->delete();

        $this->audit->handle(AuditAction::TwinDeleted, $twin, $twin->name);

        Storage::disk(config()->string('meridian.assets_disk'))->deleteDirectory($directory);
    }
}
