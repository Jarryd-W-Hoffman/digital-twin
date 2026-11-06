<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TwinFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read string $id
 * @property-read string $organisation_id
 * @property-read string $name
 * @property-read string|null $description
 * @property-read Organisation $organisation
 * @property-read TwinVersion|null $latestVersion
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class Twin extends Model
{
    /** @use HasFactory<TwinFactory> */
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
            'name' => 'string',
            'description' => 'string',
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
     * @return HasMany<TwinAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(TwinAsset::class);
    }

    /**
     * Where this twin's uploaded files live on the assets disk.
     */
    public function storagePrefix(): string
    {
        return 'twins/'.$this->id;
    }
}
