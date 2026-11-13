<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiTokenAccess;
use Carbon\CarbonInterface;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A key another system uses to reach an organisation's data through the
 * API. It belongs to the organisation, not to whoever made it, so it keeps
 * working after they leave; only its hash is kept.
 *
 * @property-read string $id
 * @property-read string $organisation_id
 * @property-read string|null $created_by
 * @property-read string $name
 * @property-read string $token_hash
 * @property-read string $hint
 * @property-read ApiTokenAccess $access
 * @property-read CarbonInterface|null $expires_at
 * @property-read CarbonInterface|null $last_used_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Organisation $organisation
 * @property-read User|null $creator
 */
#[Hidden(['token_hash'])]
final class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    use HasUuids;

    public const string PREFIX = 'mrd_';

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'organisation_id' => 'string',
            'created_by' => 'string',
            'name' => 'string',
            'token_hash' => 'string',
            'hint' => 'string',
            'access' => ApiTokenAccess::class,
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->isPast() ?? false;
    }

    /**
     * How the token is shown once it can't be: its prefix and last four.
     */
    public function masked(): string
    {
        return self::PREFIX.'…'.$this->hint;
    }

    /**
     * Whether something belongs to the token's organisation.
     */
    public function reaches(Project|Twin $model): bool
    {
        return $model->organisation_id === $this->organisation_id;
    }
}
