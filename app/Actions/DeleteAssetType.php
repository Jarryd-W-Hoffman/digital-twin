<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\AssetType;

/**
 * Takes a type out of the library. Twins hold their own copies, so they keep
 * running unchanged.
 */
final readonly class DeleteAssetType
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(AssetType $type): void
    {
        $type->delete();

        $this->audit->handle(AuditAction::TypeDeleted, $type->organisation, $type->name, ['id' => $type->slug]);
    }
}
