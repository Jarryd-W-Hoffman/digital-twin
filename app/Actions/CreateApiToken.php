<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ApiTokenAccess;
use App\Models\ApiToken;
use App\Models\Organisation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Makes a key for another system to reach an organisation's data. Only its
 * hash is kept, so the token is returned once and can't be shown again.
 */
final readonly class CreateApiToken
{
    /**
     * @return array{ApiToken, string}
     */
    public function handle(Organisation $organisation, User $creator, string $name, ApiTokenAccess $access, ?CarbonInterface $expiresAt): array
    {
        $token = ApiToken::PREFIX.Str::random(48);

        $apiToken = $organisation->apiTokens()->create([
            'created_by' => $creator->id,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'hint' => mb_substr($token, -4),
            'access' => $access,
            'expires_at' => $expiresAt,
        ]);

        return [$apiToken, $token];
    }
}
