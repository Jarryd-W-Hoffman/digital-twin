<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SsoConnection;
use App\Models\SsoDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SsoDomain>
 */
final class SsoDomainFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sso_connection_id' => SsoConnection::factory(),
            'domain' => fake()->unique()->domainName(),
        ];
    }
}
