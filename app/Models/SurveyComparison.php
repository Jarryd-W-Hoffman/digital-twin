<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SurveyComparisonStatus;
use Carbon\CarbonInterface;
use Database\Factories\SurveyComparisonFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An area of a twin where surveys of the ground (terrain or point clouds
 * from its project's library) are compared: each with the one before, as the
 * volume cut and filled, and an image of where.
 *
 * @property-read string $id
 * @property-read string $twin_id
 * @property-read string $name
 * @property-read list<array{0: float, 1: float}> $area
 * @property-read list<string> $surveys
 * @property-read SurveyComparisonStatus $status
 * @property-read list<array{before: string, after: string, cut: float, fill: float, net: float, areaM2: float, coveredM2: float, cutM2: float, fillM2: float, deepestCut: float, highestFill: float, cell: float, bounds: list<float>}>|null $results
 * @property-read string|null $error
 * @property-read CarbonInterface|null $measured_at
 * @property-read string|null $created_by
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Twin $twin
 */
final class SurveyComparison extends Model
{
    /** @use HasFactory<SurveyComparisonFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'area' => 'array',
            'surveys' => 'array',
            'status' => SurveyComparisonStatus::class,
            'results' => 'array',
            'measured_at' => 'datetime',
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
     * Where the image of the change between survey $pair and the one before
     * it is kept, on the assets disk.
     */
    public function imageKey(int $pair): string
    {
        return 'comparisons/'.$this->id.'/'.$pair.'.png';
    }
}
