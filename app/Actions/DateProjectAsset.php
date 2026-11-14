<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\DB;

final readonly class DateProjectAsset
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Says when an upload was captured (null: not dated), which decides when
     * twins that show it show it.
     */
    public function handle(ProjectAsset $asset, ?string $capturedOn): void
    {
        DB::transaction(function () use ($asset, $capturedOn): void {
            $asset->update(['captured_on' => $capturedOn]);

            $this->audit->handle(AuditAction::UploadDated, $asset->project, $asset->name, ['capturedOn' => $capturedOn]);
        });
    }
}
