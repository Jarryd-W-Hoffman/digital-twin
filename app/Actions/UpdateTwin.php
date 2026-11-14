<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Twin;

/**
 * Renames a twin or changes its description. Its versions keep the name
 * they were saved with.
 */
final readonly class UpdateTwin
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Twin $twin, string $name, ?string $description): void
    {
        $was = $twin->name;

        $twin->update(['name' => $name, 'description' => $description]);

        $this->audit->handle(AuditAction::TwinRenamed, $twin, $name, $was === $name ? [] : ['was' => $was]);
    }
}
