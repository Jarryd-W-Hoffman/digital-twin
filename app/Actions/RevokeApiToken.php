<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiToken;

/**
 * Stops a token working at once: anything still using it gets a 401.
 */
final readonly class RevokeApiToken
{
    public function handle(ApiToken $token): void
    {
        $token->delete();
    }
}
