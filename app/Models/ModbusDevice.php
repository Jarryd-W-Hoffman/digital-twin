<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ModbusDeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PLC, power meter, drive or other device the project's gateways read over
 * Modbus TCP. Twins map its registers by the device's name.
 *
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $name
 * @property-read string $host
 * @property-read int $port
 * @property-read int $unit_id
 * @property-read Project $project
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class ModbusDevice extends Model
{
    /** @use HasFactory<ModbusDeviceFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'port' => 'integer',
            'unit_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * What a gateway needs to connect.
     *
     * @return array{name: string, host: string, port: int, unitId: int}
     */
    public function forGateway(): array
    {
        return ['name' => $this->name, 'host' => $this->host, 'port' => $this->port, 'unitId' => $this->unit_id];
    }
}
