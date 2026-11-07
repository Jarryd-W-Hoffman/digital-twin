<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ReadingFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Events\ModelsPruned;

/**
 * One sensor reading of a twin's asset metric, as a gateway reported it.
 * Readings have no key of their own; they are written in bulk and read back
 * in time buckets.
 *
 * @property-read string $twin_id
 * @property-read string $asset
 * @property-read string $metric
 * @property-read float $value
 * @property-read CarbonInterface $recorded_at
 */
#[WithoutIncrementing]
#[WithoutTimestamps]
final class Reading extends Model
{
    /** @use HasFactory<ReadingFactory> */
    use HasFactory;

    use MassPrunable;

    protected $primaryKey;

    /**
     * @return BelongsTo<Twin, $this>
     */
    public function twin(): BelongsTo
    {
        return $this->belongsTo(Twin::class);
    }

    /**
     * Readings past the retention period. TimescaleDB drops whole chunks on
     * its own; this keeps a plain table in step.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('recorded_at', '<', now()->subDays(config()->integer('meridian.readings.retention_days')));
    }

    /**
     * One delete by time rather than MassPrunable's chunks: those match rows
     * by ctid, which a TimescaleDB hypertable repeats across its chunks, so
     * they would delete recent readings too. Run daily, it's a day's worth.
     */
    public function pruneAll(int $chunkSize = 1000): int
    {
        $deleted = $this->prunable()->delete();
        $total = is_int($deleted) ? $deleted : 0;

        if ($total > 0) {
            event(new ModelsPruned(self::class, $total));
        }

        return $total;
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'value' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
