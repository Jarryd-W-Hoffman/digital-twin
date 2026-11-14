<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Twin;
use App\Models\TwinShareLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TwinShareLink>
 */
final class TwinShareLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(40);

        return [
            'twin_id' => Twin::factory(),
            'name' => fake()->company(),
            'token_hash' => hash('sha256', $token),
            'hint' => mb_substr($token, -4),
            'live' => true,
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * Opened by a token the test knows.
     */
    public function token(string $token): self
    {
        return $this->state(['token_hash' => hash('sha256', $token), 'hint' => mb_substr($token, -4)]);
    }
}
