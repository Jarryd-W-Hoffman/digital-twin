<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AssetTypeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom asset type kept in an organisation's library, so any of its twins
 * can take a copy. The definition is the type exactly as a twin's `types`
 * holds it, and its slug is the type's id.
 *
 * @property-read string $id
 * @property-read string $organisation_id
 * @property-read string $slug
 * @property-read string $name
 * @property-read array<string, mixed> $definition
 * @property-read string|null $user_id
 * @property-read Organisation $organisation
 * @property-read User|null $user
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class AssetType extends Model
{
    /** @use HasFactory<AssetTypeFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'organisation_id' => 'string',
            'slug' => 'string',
            'name' => 'string',
            'definition' => 'array',
            'user_id' => 'string',
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
     * Who saved it last.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether a twin's type is this one, ignoring key order (JSON columns
     * may not keep it).
     */
    public function matches(mixed $type): bool
    {
        return is_array($type) && self::canonical($type) === self::canonical($this->definition);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::canonical($item) : $item, $value);
    }
}
