<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SsoIdentity>
 */
final class SsoIdentityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sso_connection_id' => SsoConnection::factory(),
            'user_id' => User::factory(),
            'subject' => fake()->uuid(),
            'email' => fake()->safeEmail(),
        ];
    }
}
