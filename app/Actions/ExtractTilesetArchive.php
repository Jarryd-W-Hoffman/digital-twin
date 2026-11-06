<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Unpacks an uploaded 3D Tiles zip onto a disk. The archive is untrusted, so
 * every entry is checked before anything is written: no path escapes, no
 * symlinks, only tile formats, and hard limits on count and size that are
 * enforced while streaming rather than trusted from the zip's own headers.
 */
final readonly class ExtractTilesetArchive
{
    private const int SYMLINK = 0120000;

    private const int TYPE_MASK = 0170000;

    /**
     * @return array{entry: string, bytes: int, files: int}
     */
    public function handle(string $archive, Filesystem $disk, string $directory): array
    {
        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            $this->fail(__('That is not a readable zip file.'));
        }

        try {
            $entries = $this->entries($zip);
            $prefix = $this->rootPrefix(array_keys($entries));
            $this->checkRoot($zip, $entries[$prefix.'tileset.json']);

            $bytes = 0;
            $limit = config()->integer('meridian.tileset.max_uncompressed_bytes');

            foreach ($entries as $name => $index) {
                $bytes += $this->copy($zip, $index, $disk, $directory.'/'.mb_substr($name, mb_strlen($prefix)), $limit - $bytes);
            }

            return ['entry' => 'tileset.json', 'bytes' => $bytes, 'files' => count($entries)];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, int> Entry name to index, files only.
     */
    private function entries(ZipArchive $zip): array
    {
        $allowed = config()->array('meridian.tileset.extensions');
        $maxFiles = config()->integer('meridian.tileset.max_files');
        $maxBytes = config()->integer('meridian.tileset.max_uncompressed_bytes');
        $declared = 0;
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                $this->fail(__('The zip is damaged.'));
            }

            $name = $stat['name'];

            if (str_ends_with($name, '/') || $this->isJunk($name)) {
                continue;
            }

            if (! $this->isSafePath($name)) {
                $this->fail(__('The zip contains an unsafe path: :name', ['name' => $name]));
            }

            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($index, $opsys, $attributes);

            if ($opsys === ZipArchive::OPSYS_UNIX && ((is_int($attributes) ? $attributes >> 16 : 0) & self::TYPE_MASK) === self::SYMLINK) {
                $this->fail(__('The zip contains a symbolic link: :name', ['name' => $name]));
            }

            $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (! in_array($extension, $allowed, true)) {
                $this->fail(__('The zip contains a file that is not part of a tileset: :name', ['name' => $name]));
            }

            $declared += $stat['size'];
            $entries[$name] = $index;

            if (count($entries) > $maxFiles || $declared > $maxBytes) {
                $this->fail(__('The tileset is larger than this server accepts.'));
            }
        }

        return $entries;
    }

    /**
     * Accepts tileset.json at the top of the zip, or inside the single folder
     * a zipped directory produces.
     *
     * @param  list<string>  $names
     */
    private function rootPrefix(array $names): string
    {
        if (in_array('tileset.json', $names, true)) {
            return '';
        }

        $tops = array_unique(array_map(fn (string $name): string => explode('/', $name)[0], $names));

        if (count($tops) === 1 && in_array($tops[0].'/tileset.json', $names, true)) {
            return $tops[0].'/';
        }

        $this->fail(__('The zip needs a tileset.json at its top level.'));
    }

    private function checkRoot(ZipArchive $zip, int $index): void
    {
        $stat = $zip->statIndex($index);
        $json = $stat !== false && $stat['size'] <= config()->integer('meridian.tileset.max_root_json_bytes') ? $zip->getFromIndex($index) : false;
        $root = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($root) || ! is_array($root['asset'] ?? null) || ! is_array($root['root'] ?? null)) {
            $this->fail(__('tileset.json is not a 3D Tiles tileset (it needs "asset" and "root").'));
        }
    }

    /**
     * Streams one entry to the disk, stopping as soon as it exceeds the budget.
     */
    private function copy(ZipArchive $zip, int $index, Filesystem $disk, string $path, int $budget): int
    {
        $source = $zip->getStreamIndex($index);
        $buffer = fopen('php://temp', 'w+b');

        if ($source === false || $buffer === false) {
            $this->fail(__('The zip is damaged.'));
        }

        try {
            // One byte over the budget is enough to know it is too big.
            $written = stream_copy_to_stream($source, $buffer, $budget + 1);

            if ($written === false || $written > $budget) {
                $this->fail(__('The tileset is larger than this server accepts.'));
            }

            rewind($buffer);
            $disk->writeStream($path, $buffer);

            return $written;
        } finally {
            fclose($source);
            fclose($buffer);
        }
    }

    private function isSafePath(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        return array_all(explode('/', $name), fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true));
    }

    private function isJunk(string $name): bool
    {
        return str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
