<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sensor or PLC that publishes readings to the project's MQTT broker. It
 * logs in with its own username and password, and may only publish below
 * the project's topic prefix.
 *
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $name
 * @property-read string|null $password_hash
 * @property-read CarbonInterface|null $last_seen_at
 * @property-read Project $project
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Hidden(['password_hash'])]
final class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'project_id' => 'string',
            'name' => 'string',
            'password_hash' => 'string',
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function mqttUsername(): string
    {
        return 'device-'.$this->id;
    }
}
