<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OpcUaSecurityMode;
use App\Enums\OpcUaSecurityPolicy;
use App\Models\OpcUaServer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpcUaServer>
 */
final class OpcUaServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->bothify('PLC ###'),
            'endpoint' => 'opc.tcp://plc.local:4840',
            'security_mode' => OpcUaSecurityMode::None,
            'security_policy' => OpcUaSecurityPolicy::None,
        ];
    }

    /**
     * Signed and encrypted, with a login.
     */
    public function secured(): self
    {
        return $this->state([
            'security_mode' => OpcUaSecurityMode::SignAndEncrypt,
            'security_policy' => OpcUaSecurityPolicy::Basic256Sha256,
            'username' => 'meridian',
            'password' => 'plc-secret',
        ]);
    }
}
