<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Reading;
use App\Models\Twin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ReadingHistory
{
    /**
     * Time bucket of recorded_at in Unix milliseconds, per driver. Plain SQL
     * rather than TimescaleDB's time_bucket, so it runs on any of them.
     */
    private const array BUCKET = [
        'pgsql' => 'floor(extract(epoch from recorded_at) * 1000 / ?)',
        'sqlite' => 'cast((julianday(recorded_at) - 2440587.5) * 86400000 / ? as integer)',
    ];

    /**
     * A metric's readings between two instants, averaged into at most
     * $buckets equal time buckets, each with its low and high.
     *
     * @return array{bucketMs: int, points: list<array{t: int, mean: float, min: float, max: float, count: int}>}
     */
    public function handle(Twin $twin, string $asset, string $metric, int $fromMs, int $toMs, int $buckets = 300): array
    {
        $bucketMs = max(1000, (int) ceil(($toMs - $fromMs) / max(1, $buckets)));
        $bucket = self::BUCKET[DB::connection()->getDriverName()] ?? self::BUCKET['pgsql'];
        $format = fn (int $ms): string => CarbonImmutable::createFromTimestampMs($ms, 'UTC')->format('Y-m-d H:i:s.vP');

        $rows = Reading::query()
            ->where('twin_id', $twin->id)
            ->where('asset', $asset)
            ->where('metric', $metric)
            ->where('recorded_at', '>=', $format($fromMs))
            ->where('recorded_at', '<', $format($toMs))
            ->selectRaw($bucket.' as bucket, avg(value) as mean, min(value) as low, max(value) as high, count(*) as n', [$bucketMs])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->toBase()
            ->get();

        $number = fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0;

        return [
            'bucketMs' => $bucketMs,
            'points' => array_values($rows->map(function (object $row) use ($bucketMs, $number): array {
                $row = (array) $row;

                return [
                    't' => (int) $number($row['bucket'] ?? null) * $bucketMs,
                    'mean' => $number($row['mean'] ?? null),
                    'min' => $number($row['low'] ?? null),
                    'max' => $number($row['high'] ?? null),
                    'count' => (int) $number($row['n'] ?? null),
                ];
            })->all()),
        ];
    }
}
