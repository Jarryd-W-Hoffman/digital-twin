<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScimUser;
use App\Models\SsoConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScimUser>
 */
final class ScimUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sso_connection_id' => SsoConnection::factory(),
            'user_id' => User::factory(),
            'user_name' => fake()->unique()->safeEmail(),
            'external_id' => fake()->uuid(),
            'active' => true,
            'created_account' => false,
        ];
    }
}
