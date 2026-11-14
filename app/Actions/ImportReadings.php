<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ReadingImportStatus;
use App\Exceptions\ReadingImportFailed;
use App\Models\Reading;
use App\Models\ReadingImport;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SplFileObject;
use SplTempFileObject;

final readonly class ImportReadings
{
    /** How many problems are described; the rest are only counted. */
    private const int PROBLEMS = 10;

    /** How many names nothing was mapped to are listed. */
    private const int UNMAPPED = 20;

    public function __construct(private RecordReadings $record) {}

    /**
     * Reads the import's file twice: once to check it and find each series'
     * first and last reading, and again to store them, in place of what the
     * twin already had for those series over that span (so importing a file
     * again doesn't double it). Times without an offset are the site's.
     *
     * @throws ReadingImportFailed
     */
    public function handle(ReadingImport $import): void
    {
        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $stream = $import->path === null ? null : $disk->readStream($import->path);
        throw_unless(is_resource($stream), ReadingImportFailed::class, __('The file is no longer there. Upload it again.'));

        // A seekable local copy: the disk may be S3, and the file is read twice.
        $file = new SplTempFileObject(8 * 1024 * 1024);
        while (! feof($stream)) {
            $file->fwrite((string) fread($stream, 1024 * 1024));
        }

        fclose($stream);

        try {
            $this->import($import, $file);
        } finally {
            $disk->delete((string) $import->path);
            $import->forceFill(['path' => null])->save();
        }
    }

    private function import(ReadingImport $import, SplFileObject $file): void
    {
        $definition = $import->twin->latestVersion()->firstOrFail()->definition;
        $offsetSeconds = (int) round((is_numeric($definition['utcOffsetHours'] ?? null) ? (float) $definition['utcOffsetHours'] : 0.0) * 3600);
        $oldest = now()->subDays(config()->integer('meridian.readings.retention_days'))->getTimestampMs();
        $newest = now()->addDay()->getTimestampMs();

        // First pass: check, count, and find each series' span.
        $spans = [];
        $earliest = PHP_INT_MAX;
        $latest = PHP_INT_MIN;
        $summary = ['rows' => 0, 'stored' => 0, 'skipped' => 0, 'problems' => [], 'unmapped' => []];
        $unmapped = [];
        $max = config()->integer('meridian.readings.import_max_readings');

        foreach ($this->rows($import, $file, $offsetSeconds) as $row) {
            if ($row['type'] === 'row') {
                $summary['rows']++;

                continue;
            }

            if ($row['type'] === 'unmapped') {
                $unmapped[$row['name']] = ($unmapped[$row['name']] ?? 0) + 1;
                $summary['skipped']++;

                continue;
            }

            if ($row['type'] === 'problem') {
                $summary['skipped']++;
                if (count($summary['problems']) < self::PROBLEMS) {
                    $summary['problems'][] = $row['message'];
                }

                continue;
            }

            $at = $row['reading']['at'];
            if ($at < $oldest || $at > $newest) {
                $summary['skipped']++;
                if (count($summary['problems']) < self::PROBLEMS) {
                    $summary['problems'][] = $at < $oldest
                        ? __('Row :row: :at is older than the :days days readings are kept.', ['row' => $row['row'], 'at' => $this->when($at), 'days' => config()->integer('meridian.readings.retention_days')])
                        : __('Row :row: :at is in the future.', ['row' => $row['row'], 'at' => $this->when($at)]);
                }

                continue;
            }

            $key = $row['reading']['asset'].'.'.$row['reading']['metric'];
            $spans[$key] = [
                'asset' => $row['reading']['asset'],
                'metric' => $row['reading']['metric'],
                'from' => min($spans[$key]['from'] ?? $at, $at),
                'to' => max($spans[$key]['to'] ?? $at, $at),
            ];
            $earliest = min($earliest, $at);
            $latest = max($latest, $at);
            $summary['stored']++;
            throw_if($summary['stored'] > $max, ReadingImportFailed::class, __('The file has more than :max readings. Split it into smaller files.', ['max' => number_format($max)]));
        }

        arsort($unmapped);
        foreach (array_keys(array_slice($unmapped, 0, self::UNMAPPED, true)) as $name) {
            $summary['unmapped'][] = (string) $name;
        }

        throw_if($summary['stored'] === 0, ReadingImportFailed::class, $summary['problems'] === []
            ? __('The file has no readings for the columns chosen.')
            : __('The file has no readings that could be imported. :problem', ['problem' => $summary['problems'][0]]));

        // Second pass: replace each series over its span with the file's readings.
        DB::transaction(function () use ($import, $file, $offsetSeconds, $oldest, $newest, $spans): void {
            foreach ($spans as $span) {
                Reading::query()
                    ->where('twin_id', $import->twin_id)
                    ->where('asset', $span['asset'])
                    ->where('metric', $span['metric'])
                    ->whereBetween('recorded_at', [RecordReadings::format($span['from']), RecordReadings::format($span['to'])])
                    ->delete();
            }

            $batch = [];
            foreach ($this->rows($import, $file, $offsetSeconds) as $row) {
                if ($row['type'] !== 'reading' || $row['reading']['at'] < $oldest || $row['reading']['at'] > $newest) {
                    continue;
                }

                $batch[] = $row['reading'];
                if (count($batch) === 1000) {
                    $this->record->handle($import->twin, $batch);
                    $batch = [];
                }
            }

            $this->record->handle($import->twin, $batch);
        });

        $import->forceFill([
            'status' => ReadingImportStatus::Done,
            'summary' => $summary,
            // To the second around them, as the database keeps them.
            'first_at' => CarbonImmutable::createFromTimestamp(intdiv($earliest, 1000), 'UTC'),
            'last_at' => CarbonImmutable::createFromTimestamp((int) ceil($latest / 1000), 'UTC'),
            'error' => null,
        ])->save();
    }

    /**
     * Each row of the file, from the start: a row read, and then its readings,
     * names nothing was mapped to, or what's wrong with it.
     *
     * @return Generator<int, array{type: 'row'}|array{type: 'reading', row: int, reading: array{asset: string, metric: string, value: float, at: int}}|array{type: 'unmapped', name: string}|array{type: 'problem', message: string}>
     */
    private function rows(ReadingImport $import, SplFileObject $file, int $offsetSeconds): Generator
    {
        $settings = $import->settings;
        $file->rewind();
        $header = $file->fgetcsv($settings['delimiter'], '"', '');
        throw_unless(is_array($header) && $header !== [null], ReadingImportFailed::class, __('The file is empty.'));
        $header = array_map(fn (?string $name): string => mb_trim((string) $name), $header);
        $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]) ?? $header[0];
        $column = function (string $name) use ($header): int {
            $index = array_search($name, $header, true);
            throw_if($index === false, ReadingImportFailed::class, __('The file has no column “:name”.', ['name' => $name]));

            return $index;
        };

        $time = $column($settings['time']);
        $long = $settings['layout'] === 'long';
        $nameColumn = $long ? $column((string) ($settings['name'] ?? '')) : 0;
        $valueColumn = $long ? $column((string) ($settings['value'] ?? '')) : 0;

        /** @var array<string, list<array{asset: string, metric: string}>> $targets */
        $targets = [];
        /** @var array<int, list<array{asset: string, metric: string}>> $columns */
        $columns = [];
        foreach ($import->series as $series) {
            $target = ['asset' => $series['asset'], 'metric' => $series['metric']];
            if ($long) {
                $targets[$series['name']][] = $target;
            } else {
                $columns[$column($series['name'])][] = $target;
            }
        }

        $line = 1;
        while (! $file->eof() && ($cells = $file->fgetcsv($settings['delimiter'], '"', '')) !== false) {
            $line++;
            if ($cells === [null] || $cells === ['']) {
                continue;
            }

            yield ['type' => 'row'];

            $at = $this->time(mb_trim($cells[$time] ?? ''), $settings['format'], $offsetSeconds);
            if ($at === null) {
                yield ['type' => 'problem', 'message' => __("Row :row: “:time” isn't a time the importer can read.", ['row' => $line, 'time' => mb_strimwidth(mb_trim($cells[$time] ?? ''), 0, 40, '…')])];

                continue;
            }

            if ($long) {
                $name = mb_trim($cells[$nameColumn] ?? '');
                if (! array_key_exists($name, $targets)) {
                    yield ['type' => 'unmapped', 'name' => $name];

                    continue;
                }

                $cellsFor = [$valueColumn => $targets[$name]];
            } else {
                $cellsFor = $columns;
            }

            foreach ($cellsFor as $index => $series) {
                $cell = mb_trim($cells[$index] ?? '');
                if ($cell === '') {
                    continue;
                }

                $value = $this->number($cell, $settings['delimiter']);
                if ($value === null) {
                    yield ['type' => 'problem', 'message' => __("Row :row: “:value” in :column isn't a number.", ['row' => $line, 'value' => mb_strimwidth($cell, 0, 40, '…'), 'column' => $header[$index] ?? ''])];

                    continue;
                }

                foreach ($series as $target) {
                    yield ['type' => 'reading', 'row' => $line, 'reading' => [...$target, 'value' => $value, 'at' => $at]];
                }
            }
        }
    }

    /**
     * A cell as a number: plain, or with a decimal comma where commas don't
     * separate the columns.
     */
    private function number(string $cell, string $delimiter): ?float
    {
        if ($delimiter !== ',' && preg_match('/^-?\d+,\d+$/', $cell) === 1) {
            $cell = str_replace(',', '.', $cell);
        }

        return is_numeric($cell) && is_finite((float) $cell) ? (float) $cell : null;
    }

    /**
     * A time in Unix milliseconds: ISO 8601 (2026-03-01 06:30, with seconds,
     * fractions and an offset if it has them), day or month first
     * (01/03/2026 6:30 pm), or Unix seconds or milliseconds. Without an
     * offset it's the site's time.
     */
    private function time(string $cell, string $format, int $offsetSeconds): ?int
    {
        if ($format === 'unix') {
            if (preg_match('/^\d{9,13}(\.\d+)?$/', $cell) !== 1) {
                return null;
            }

            $number = (float) $cell;

            return (int) round($number >= 1e11 ? $number : $number * 1000);
        }

        if ($format === 'iso') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{1,2}):(\d{2})(?::(\d{2})(?:[.,](\d{1,6}))?)?)?\s*(Z|[+-]\d{2}(?::?\d{2})?)?$/i', $cell, $m) !== 1) {
                return null;
            }

            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$hour, $minute, $second] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
            $millis = (int) round((float) ('0.'.(($m[7] ?? '') === '' ? '0' : $m[7])) * 1000);
            $zone = $m[8] ?? '';
            if ($zone !== '') {
                $offsetSeconds = $this->zone($zone);
            }
        } else {
            if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4}|\d{2})(?:[ T,]+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(am|pm)?)?$/i', $cell, $m) !== 1) {
                return null;
            }

            [$day, $month] = $format === 'dmy' ? [(int) $m[1], (int) $m[2]] : [(int) $m[2], (int) $m[1]];
            $year = mb_strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            [$hour, $minute, $second, $millis] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), 0];
            $half = mb_strtolower($m[7] ?? '');
            if ($half !== '') {
                if ($hour < 1 || $hour > 12) {
                    return null;
                }

                $hour = ($hour % 12) + ($half === 'pm' ? 12 : 0);
            }
        }

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return (gmmktime($hour, $minute, $second, $month, $day, $year) - $offsetSeconds) * 1000 + $millis;
    }

    /**
     * Seconds east of UTC in Z, +13, +1300 or -05:30.
     */
    private function zone(string $zone): int
    {
        if (mb_strtoupper($zone) === 'Z') {
            return 0;
        }

        $digits = str_replace(':', '', mb_substr($zone, 1));
        $seconds = (int) mb_substr($digits, 0, 2) * 3600 + (int) (mb_substr($digits, 2, 2) ?: 0) * 60;

        return $zone[0] === '-' ? -$seconds : $seconds;
    }

    /**
     * A time as UTC, for a problem's description.
     */
    private function when(int $at): string
    {
        return CarbonImmutable::createFromTimestampMs($at, 'UTC')->format('Y-m-d H:i').' UTC';
    }
}
