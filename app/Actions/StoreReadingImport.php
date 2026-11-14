<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\ReadingImportStatus;
use App\Jobs\ImportReadingFile;
use App\Models\ReadingImport;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final readonly class StoreReadingImport
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Keeps a CSV file of readings and queues it to be imported into the
     * twin's history, per its layout and how its series map onto metrics.
     *
     * @param  array{layout: 'wide'|'long', delimiter: string, time: string, format: 'iso'|'dmy'|'mdy'|'unix', name?: string|null, value?: string|null}  $settings
     * @param  list<array{name: string, asset: string, metric: string}>  $series
     */
    public function handle(Twin $twin, ?User $user, UploadedFile $file, array $settings, array $series): ReadingImport
    {
        $import = $twin->readingImports()->create([
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'settings' => $settings,
            'series' => $series,
            'status' => ReadingImportStatus::Importing,
            'created_by' => $user?->id,
        ]);

        $path = 'imports/'.$import->id.'.csv';
        Storage::disk(config()->string('meridian.assets_disk'))->putFileAs('imports', $file, $import->id.'.csv');
        $import->forceFill(['path' => $path])->save();

        dispatch(new ImportReadingFile($import));

        $this->audit->handle(AuditAction::ReadingsImported, $twin, $import->file_name, ['series' => count($series)]);

        return $import;
    }
}
