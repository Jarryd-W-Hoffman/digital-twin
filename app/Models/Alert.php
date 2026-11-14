<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Support\Actor;
use Carbon\CarbonInterface;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One alert a twin's gateway raised: a rule breached on an asset, from when
 * the gateway first reported it open until it stopped, and who acknowledged
 * it. The gateway names it by rule and asset, so at most one of each is open.
 *
 * @property-read string $id
 * @property-read string $twin_id
 * @property-read string $rule
 * @property-read string $asset
 * @property-read AlertSeverity $severity
 * @property-read string $message
 * @property-read float $value
 * @property-read CarbonInterface $raised_at
 * @property-read CarbonInterface|null $cleared_at
 * @property-read CarbonInterface|null $acknowledged_at
 * @property-read string|null $acknowledged_by
 * @property-read string|null $acknowledged_by_api
 * @property-read Twin $twin
 * @property-read User|null $acknowledger
 * @property-read Collection<int, AlertNotification> $notifications
 * @property-read Collection<int, Issue> $issues
 */
#[WithoutTimestamps]
final class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
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
            'rule' => 'string',
            'asset' => 'string',
            'severity' => AlertSeverity::class,
            'message' => 'string',
            'value' => 'float',
            'raised_at' => 'datetime',
            'cleared_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'acknowledged_by' => 'string',
            'acknowledged_by_api' => 'string',
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
     * Who was told, or held back from being told, of its raising and clearing.
     *
     * @return HasMany<AlertNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(AlertNotification::class);
    }

    /**
     * The issues raised from it, to follow it up.
     *
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Who acknowledged it: a person, or the API token that did.
     */
    public function acknowledgerName(): ?string
    {
        return $this->acknowledger->name ?? Actor::apiName($this->acknowledged_by_api);
    }
}
