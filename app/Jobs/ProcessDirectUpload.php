<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ProcessProjectAssetFile;
use App\Enums\ProjectAssetStatus;
use App\Models\ProjectAsset;
use App\Support\DirectUploads;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Checks and unpacks a file the browser uploaded straight to the bucket, then
 * removes the uploaded original. A file that fails its checks is marked
 * failed with the reason, and is not retried.
 */
#[Backoff([10, 60, 300])]
#[Timeout(1800)]
#[Tries(3)]
final class ProcessDirectUpload implements ShouldQueue
{
    use Queueable;

    public function __construct(public ProjectAsset $asset) {}

    public function handle(DirectUploads $uploads, ProcessProjectAssetFile $process): void
    {
        // Removed by PHP once closed.
        $file = tmpfile();
        $path = $file === false ? null : (stream_get_meta_data($file)['uri'] ?? null);

        throw_if($file === false || $path === null, RuntimeException::class, 'No temporary file for the upload.');

        try {
            $uploads->download($this->asset->sourceKey(), $path);
            $process->handle($this->asset, $path);
            $uploads->delete($this->asset->sourceKey());
        } catch (ValidationException $validationException) {
            $uploads->delete($this->asset->sourceKey());
            $this->asset->update(['status' => ProjectAssetStatus::Failed, 'error' => $validationException->getMessage()]);
        } finally {
            fclose($file);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->asset->update([
            'status' => ProjectAssetStatus::Failed,
            'error' => __('Processing failed. Try uploading it again.'),
        ]);
    }
}
