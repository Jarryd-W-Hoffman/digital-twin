<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApiTokenAccess;
use App\Models\ApiToken;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiToken>
 */
final class ApiTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = ApiToken::PREFIX.Str::random(48);

        return [
            'organisation_id' => Organisation::factory(),
            'name' => 'Reporting',
            'token_hash' => hash('sha256', $token),
            'hint' => mb_substr($token, -4),
            'access' => ApiTokenAccess::Read,
        ];
    }

    /**
     * The token whose hash is kept, to send as a bearer token.
     */
    public function token(string $token): self
    {
        return $this->state(['token_hash' => hash('sha256', $token), 'hint' => mb_substr($token, -4)]);
    }

    /**
     * May add readings, raise, comment on and close issues, and acknowledge
     * alerts.
     */
    public function writes(): self
    {
        return $this->state(['access' => ApiTokenAccess::Write]);
    }
}
