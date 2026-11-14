<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReadingImportStatus;
use Carbon\CarbonInterface;
use Database\Factories\ReadingImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CSV file of readings imported into a twin's history. Either each of its
 * columns is a series (wide), or a column names the series and another holds
 * the value (long); `series` maps those names onto the twin's metrics.
 *
 * @property-read string $id
 * @property-read string $twin_id
 * @property-read string $file_name
 * @property-read string|null $path
 * @property-read array{layout: 'wide'|'long', delimiter: string, time: string, format: 'iso'|'dmy'|'mdy'|'unix', name?: string|null, value?: string|null} $settings
 * @property-read list<array{name: string, asset: string, metric: string}> $series
 * @property-read ReadingImportStatus $status
 * @property-read array{rows: int, stored: int, skipped: int, problems: list<string>, unmapped: list<string>}|null $summary
 * @property-read CarbonInterface|null $first_at
 * @property-read CarbonInterface|null $last_at
 * @property-read string|null $error
 * @property-read string|null $created_by
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Twin $twin
 * @property-read User|null $creator
 */
final class ReadingImport extends Model
{
    /** @use HasFactory<ReadingImportFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'settings' => 'array',
            'series' => 'array',
            'status' => ReadingImportStatus::class,
            'summary' => 'array',
            'first_at' => 'datetime',
            'last_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Twin, $this>
     */
    public function twin(): BelongsTo
    {
        return $this->belongsTo(Twin::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
