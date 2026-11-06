<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A body of work inside an organisation: its twins, and the library of
 * uploads they draw their layers from.
 *
 * @property-read string $id
 * @property-read string $organisation_id
 * @property-read string $name
 * @property-read string|null $description
 * @property-read Organisation $organisation
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
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
     * @return HasMany<Twin, $this>
     */
    public function twins(): HasMany
    {
        return $this->hasMany(Twin::class);
    }

    /**
     * @return HasMany<ProjectAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(ProjectAsset::class);
    }

    /**
     * Where this project's uploads are stored on the assets disk.
     */
    public function storagePrefix(): string
    {
        return 'projects/'.$this->id;
    }
}
