<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TwinShareLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link that shows one twin, read only, to someone who isn't a member.
 *
 * @property-read string $id
 * @property-read string $twin_id
 * @property-read string|null $created_by
 * @property-read string $name
 * @property-read string $token_hash
 * @property-read string $hint
 * @property-read bool $live
 * @property-read CarbonInterface $expires_at
 * @property-read CarbonInterface|null $last_viewed_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Twin $twin
 * @property-read User|null $creator
 */
#[Hidden(['token_hash'])]
final class TwinShareLink extends Model
{
    /** @use HasFactory<TwinShareLinkFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The link a token opens, while it hasn't expired.
     */
    public static function opening(string $token): ?self
    {
        return self::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'twin_id' => 'string',
            'created_by' => 'string',
            'name' => 'string',
            'token_hash' => 'string',
            'hint' => 'string',
            'live' => 'boolean',
            'expires_at' => 'datetime',
            'last_viewed_at' => 'datetime',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Notes a visit, at most once a minute.
     */
    public function viewed(): void
    {
        if (! $this->last_viewed_at?->isAfter(now()->subMinute())) {
            self::query()->whereKey($this->id)->update(['last_viewed_at' => now()]);
        }
    }

    /**
     * The library uploads the twin's latest version shows, by id: all a link
     * may load.
     *
     * @return list<string>
     */
    public function assetIds(): array
    {
        $definition = $this->twin->latestVersion()->firstOrFail()->definition;
        $layers = [
            ...(is_array($definition['tilesets'] ?? null) ? $definition['tilesets'] : []),
            ...(is_array($definition['models'] ?? null) ? $definition['models'] : []),
            ...(is_array($definition['vectors'] ?? null) ? $definition['vectors'] : []),
            ...(is_array($definition['imagery'] ?? null) ? $definition['imagery'] : []),
            $definition['terrain'] ?? null,
        ];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $layer): ?string => is_array($layer) && is_string($layer['assetId'] ?? null) ? $layer['assetId'] : null,
            $layers,
        ), is_string(...))));
    }
}
