<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SavedViewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a twin looked to someone, kept to come back to or to share: the
 * camera, what the scene is coloured by, the basemap and layers, any section
 * cut and the selected asset. Private to its author until shared with
 * everyone who can see the twin; a link opens it either way.
 *
 * @property-read string $id
 * @property-read string $twin_id
 * @property-read string|null $author_id
 * @property-read string $name
 * @property-read array{camera: array{lon: float, lat: float, height: float, heading: float, pitch: float, roll: float}, colorBy: string, basemap: string, layers: array<string, bool>, section: array{above: float|null, across: array{offset: float, heading: float}|null}|null, selected: string|null} $state
 * @property-read bool $shared
 * @property-read Twin $twin
 * @property-read User|null $author
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class SavedView extends Model
{
    /** @use HasFactory<SavedViewFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'twin_id' => 'string',
            'author_id' => 'string',
            'name' => 'string',
            'state' => 'array',
            'shared' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
