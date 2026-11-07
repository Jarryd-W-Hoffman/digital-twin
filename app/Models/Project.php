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
use Illuminate\Support\Str;

/**
 * A body of work inside an organisation: its twins, and the library of
 * uploads they draw their layers from.
 *
 * @property-read string $id
 * @property-read string $organisation_id
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string $mqtt_key
 * @property-read Organisation $organisation
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUuids;

    public static function booted(): void
    {
        self::creating(function (Project $project): void {
            if ($project->getAttribute('mqtt_key') === null) {
                $project->setAttribute('mqtt_key', self::newMqttKey($project->name));
            }
        });
    }

    /**
     * A readable, unused key for a project's topics: its name as a slug and a
     * random suffix, fixed from then on so renaming doesn't move devices.
     */
    public static function newMqttKey(string $name): string
    {
        $slug = mb_rtrim(Str::limit(Str::slug($name), 20, ''), '-') ?: 'project';

        do {
            $key = $slug.'-'.Str::lower(Str::random(4));
        } while (self::query()->where('mqtt_key', $key)->exists());

        return $key;
    }

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
            'mqtt_key' => 'string',
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

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Every MQTT topic in this project starts with this. Devices publish
     * below it, gateways subscribe below it, and telemetry mappings are
     * written relative to it.
     */
    public function topicPrefix(): string
    {
        return 'p/'.$this->mqtt_key.'/';
    }
}
