<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File as LocalFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The steps every conversion shares: a working copy of the upload, running
 * the tools with their last lines kept as the reason when they fail, storing
 * what they wrote, and a short progress log on the library item.
 */
final readonly class Converter
{
    private const int LOG_LIMIT = 16_000;

    public function download(Filesystem $disk, string $key, string $path): void
    {
        $from = $disk->readStream($key);
        throw_if($from === null, ConversionFailed::class, __('The uploaded file is missing. Upload it again.'));

        $to = fopen($path, 'wb');
        throw_if($to === false, ConversionFailed::class, __('Could not make a working copy of the upload.'));

        stream_copy_to_stream($from, $to);
        fclose($to);
        fclose($from);
    }

    /**
     * The first bytes of a file, to tell what it really is.
     *
     * @param  positive-int  $length
     */
    public function header(string $path, int $length): string
    {
        $handle = fopen($path, 'rb');
        $header = $handle === false ? false : fread($handle, $length);

        if ($handle !== false) {
            fclose($handle);
        }

        return is_string($header) ? $header : '';
    }

    /**
     * @param  list<string>  $command
     */
    public function run(array $command, string $failure, int $timeoutMinutes): string
    {
        $result = Process::timeout($timeoutMinutes * 60)->run($command);

        if ($result->failed()) {
            $detail = collect(explode("\n", mb_trim($result->errorOutput()."\n".$result->output())))
                ->filter()
                ->take(-5)
                ->implode("\n");

            throw new ConversionFailed($failure.($detail === '' ? '.' : ":\n".$detail));
        }

        return $result->output();
    }

    /**
     * Copies everything under $from into $directory on the disk.
     *
     * @return array{entry: string, bytes: int, files: int}
     */
    public function store(Filesystem $disk, string $from, string $directory, string $entry): array
    {
        $bytes = 0;
        $files = File::allFiles($from);

        foreach ($files as $file) {
            $folder = $file->getRelativePath();
            $disk->putFileAs($folder === '' ? $directory : $directory.'/'.$folder, new LocalFile($file->getPathname()), $file->getFilename());
            $bytes += $file->getSize();
        }

        return ['entry' => $entry, 'bytes' => $bytes, 'files' => count($files)];
    }

    /**
     * Adds a line to the conversion's log, and moves its progress on (never
     * back: a mesh from photos carries on from where processing them left it).
     */
    public function note(ProjectAsset $asset, int $progress, string $line): void
    {
        $log = mb_ltrim(($asset->log ?? '')."\n".$line);

        $asset->update(['progress' => max($progress, $asset->progress ?? 0), 'log' => mb_substr($log, -self::LOG_LIMIT)]);
    }
}
