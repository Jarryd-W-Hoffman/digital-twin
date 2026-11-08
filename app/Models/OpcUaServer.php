<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OpcUaSecurityMode;
use App\Enums\OpcUaSecurityPolicy;
use Carbon\CarbonInterface;
use Database\Factories\OpcUaServerFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PLC, SCADA system or other OPC UA server the project's gateways read
 * from. Twins map its nodes by the server's name; its password is stored
 * encrypted and only ever sent to the project's gateways.
 *
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $name
 * @property-read string $endpoint
 * @property-read OpcUaSecurityMode $security_mode
 * @property-read OpcUaSecurityPolicy $security_policy
 * @property-read string|null $username
 * @property-read string|null $password
 * @property-read Project $project
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Hidden(['password'])]
final class OpcUaServer extends Model
{
    /** @use HasFactory<OpcUaServerFactory> */
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
            'endpoint' => 'string',
            'security_mode' => OpcUaSecurityMode::class,
            'security_policy' => OpcUaSecurityPolicy::class,
            'username' => 'string',
            'password' => 'encrypted',
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

    /**
     * What a gateway needs to connect, password included.
     *
     * @return array{name: string, endpoint: string, securityMode: string, securityPolicy: string, username: string|null, password: string|null}
     */
    public function forGateway(): array
    {
        return [
            'name' => $this->name,
            'endpoint' => $this->endpoint,
            'securityMode' => $this->security_mode->value,
            'securityPolicy' => $this->security_policy->value,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
