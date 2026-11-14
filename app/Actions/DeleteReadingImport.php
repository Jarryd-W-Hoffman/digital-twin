<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Reading;
use App\Models\ReadingImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteReadingImport
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Deletes an import and, if it was imported, the readings of its series
     * over the time it covered: those it stored, and any other readings of
     * those metrics in that span, which it had replaced.
     */
    public function handle(ReadingImport $import): void
    {
        DB::transaction(function () use ($import): void {
            if ($import->first_at !== null && $import->last_at !== null) {
                foreach ($import->series as $series) {
                    Reading::query()
                        ->where('twin_id', $import->twin_id)
                        ->where('asset', $series['asset'])
                        ->where('metric', $series['metric'])
                        ->whereBetween('recorded_at', [RecordReadings::format($import->first_at->getTimestampMs()), RecordReadings::format($import->last_at->getTimestampMs())])
                        ->delete();
                }
            }

            $import->delete();
        });

        if ($import->path !== null) {
            Storage::disk(config()->string('meridian.assets_disk'))->delete($import->path);
        }

        $this->audit->handle(AuditAction::ReadingImportDeleted, $import->twin, $import->file_name);
    }
}
