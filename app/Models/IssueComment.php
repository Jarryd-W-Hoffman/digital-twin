<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\IssueCommentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One note in an issue's conversation: words, a photo, or both. The first
 * is what the person raising the issue wrote.
 *
 * @property-read string $id
 * @property-read string $issue_id
 * @property-read string|null $author_id
 * @property-read string|null $body
 * @property-read string|null $photo
 * @property-read Issue $issue
 * @property-read User|null $author
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class IssueComment extends Model
{
    /** @use HasFactory<IssueCommentFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'issue_id' => 'string',
            'author_id' => 'string',
            'body' => 'string',
            'photo' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
