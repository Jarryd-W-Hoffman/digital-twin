<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TwinFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read string $id
 * @property-read string $organisation_id
 * @property-read string $project_id
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string|null $gateway_token_hash
 * @property-read string|null $gateway_url
 * @property-read CarbonInterface|null $gateway_seen_at
 * @property-read Organisation $organisation
 * @property-read Project $project
 * @property-read TwinVersion|null $latestVersion
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Hidden(['gateway_token_hash'])]
final class Twin extends Model
{
    /** @use HasFactory<TwinFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * A gateway polls every few seconds; after this long without a check-in
     * it is treated as offline.
     */
    public const int GATEWAY_TIMEOUT_SECONDS = 30;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'organisation_id' => 'string',
            'project_id' => 'string',
            'name' => 'string',
            'description' => 'string',
            'gateway_token_hash' => 'string',
            'gateway_url' => 'string',
            'gateway_seen_at' => 'datetime',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<TwinVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TwinVersion::class);
    }

    /**
     * @return HasOne<TwinVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(TwinVersion::class)->ofMany('number', 'max');
    }

    /**
     * Whether a gateway holding this twin's token has checked in recently.
     */
    public function gatewayOnline(): bool
    {
        return $this->gateway_token_hash !== null
            && $this->gateway_url !== null
            && $this->gateway_seen_at?->isAfter(now()->subSeconds(self::GATEWAY_TIMEOUT_SECONDS)) === true;
    }
}
