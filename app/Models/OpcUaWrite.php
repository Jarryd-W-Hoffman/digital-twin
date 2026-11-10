<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\OpcUaWriteFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One setpoint a twin's gateway wrote to an OPC UA node for an operator, or
 * tried to: the audit trail of everything Meridian changed in a plant.
 *
 * @property-read string $id
 * @property-read string $twin_id
 * @property-read string|null $user_id
 * @property-read string $server
 * @property-read string $node
 * @property-read string $asset
 * @property-read string $metric
 * @property-read float $requested
 * @property-read float $setpoint
 * @property-read float $value
 * @property-read bool $succeeded
 * @property-read string|null $error
 * @property-read CarbonInterface $written_at
 * @property-read Twin $twin
 * @property-read User|null $user
 */
#[WithoutTimestamps]
final class OpcUaWrite extends Model
{
    /** @use HasFactory<OpcUaWriteFactory> */
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
            'user_id' => 'string',
            'server' => 'string',
            'node' => 'string',
            'asset' => 'string',
            'metric' => 'string',
            'requested' => 'float',
            'setpoint' => 'float',
            'value' => 'float',
            'succeeded' => 'boolean',
            'error' => 'string',
            'written_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
