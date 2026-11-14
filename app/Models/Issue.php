<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Actor;
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
 * @property-read string|null $author_api
 * @property-read string|null $assignee_id
 * @property-read CarbonInterface|null $due_on
 * @property-read string|null $alert_id
 * @property-read CarbonInterface|null $closed_at
 * @property-read string|null $closed_by
 * @property-read string|null $closed_by_api
 * @property-read int|null $comments_count
 * @property-read Twin $twin
 * @property-read User|null $author
 * @property-read User|null $assignee
 * @property-read Alert|null $alert
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
            'author_api' => 'string',
            'assignee_id' => 'string',
            'due_on' => 'date',
            'alert_id' => 'string',
            'closed_at' => 'datetime',
            'closed_by' => 'string',
            'closed_by_api' => 'string',
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
     * Who raised it: a person, or the API token that did.
     */
    public function authorName(): ?string
    {
        return $this->author->name ?? Actor::apiName($this->author_api);
    }

    /**
     * Who is to deal with it.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * The alert it was raised from.
     *
     * @return BelongsTo<Alert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /**
     * Whether it's still open past its due date.
     */
    public function isOverdue(): bool
    {
        return $this->closed_at === null && $this->due_on !== null && $this->due_on->lt(today());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Who closed it: a person, or the API token that did.
     */
    public function closerName(): ?string
    {
        return $this->closer->name ?? Actor::apiName($this->closed_by_api);
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
