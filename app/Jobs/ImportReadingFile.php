<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ImportReadings;
use App\Enums\ReadingImportStatus;
use App\Exceptions\ReadingImportFailed;
use App\Models\ReadingImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Imports a CSV file of readings into its twin's history. Tried once: the
 * reason it failed is shown on the import, and uploading it again retries.
 */
#[Timeout(1800)]
#[Tries(1)]
final class ImportReadingFile implements ShouldQueue
{
    use Queueable;

    public function __construct(public ReadingImport $import) {}

    public function handle(ImportReadings $import): void
    {
        try {
            $import->handle($this->import);
        } catch (ReadingImportFailed $readingImportFailed) {
            $this->import->update(['status' => ReadingImportStatus::Failed, 'error' => $readingImportFailed->getMessage()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->import->update([
            'status' => ReadingImportStatus::Failed,
            'error' => __('Importing stopped unexpectedly. Upload the file again to retry.'),
        ]);
    }
}
