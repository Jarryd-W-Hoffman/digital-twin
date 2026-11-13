<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A note or problem pinned to a place in a twin's scene: where it is, the
 * camera it was raised from, and the conversation about it. Numbered per
 * twin, so people can say "issue 12".
 *
 * @property-read string $id
 * @property-read string $twin_id
 * @property-read int $number
 * @property-read string $title
 * @property-read float $lon
 * @property-read float $lat
 * @property-read float $height
 * @property-read array{lon: float, lat: float, height: float, heading: float, pitch: float, roll: float} $view
 * @property-read string|null $author_id
 * @property-read CarbonInterface|null $closed_at
 * @property-read string|null $closed_by
 * @property-read int|null $comments_count
 * @property-read Twin $twin
 * @property-read User|null $author
 * @property-read User|null $closer
 * @property-read Collection<int, IssueComment> $comments
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
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
            'number' => 'integer',
            'title' => 'string',
            'lon' => 'float',
            'lat' => 'float',
            'height' => 'float',
            'view' => 'array',
            'author_id' => 'string',
            'closed_at' => 'datetime',
            'closed_by' => 'string',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<IssueComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(IssueComment::class);
    }

    /**
     * Where its photos are kept on the assets disk.
     */
    public function storagePrefix(): string
    {
        return $this->twin->storagePrefix().'/issues/'.$this->id;
    }
}
