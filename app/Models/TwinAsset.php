<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TwinAssetKind;
use Carbon\CarbonInterface;
use Database\Factories\TwinAssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $twin_id
 * @property-read TwinAssetKind $kind
 * @property-read string $name
 * @property-read string $original_name
 * @property-read string $entry
 * @property-read int $bytes
 * @property-read int $files
 * @property-read Twin $twin
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class TwinAsset extends Model
{
    /** @use HasFactory<TwinAssetFactory> */
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
            'kind' => TwinAssetKind::class,
            'name' => 'string',
            'original_name' => 'string',
            'entry' => 'string',
            'bytes' => 'integer',
            'files' => 'integer',
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

    public function directory(): string
    {
        return $this->twin->storagePrefix().'/assets/'.$this->id;
    }

    /**
     * The URL the viewer loads: the model file, or the tileset's root JSON.
     */
    public function url(): string
    {
        return route('twins.assets.files.show', [$this->twin_id, $this->id, $this->entry], absolute: false);
    }
}
