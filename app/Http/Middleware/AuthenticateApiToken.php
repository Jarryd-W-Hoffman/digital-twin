<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Other systems authenticate with an organisation's API token as a bearer
 * token. The token is left on the request, and when it was last used is
 * noted at most once a minute.
 */
final readonly class AuthenticateApiToken
{
    public static function token(Request $request): ApiToken
    {
        $token = $request->attributes->get('apiToken');
        assert($token instanceof ApiToken);

        return $token;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = is_string($bearer) && str_starts_with($bearer, ApiToken::PREFIX)
            ? ApiToken::query()->where('token_hash', hash('sha256', $bearer))->first()
            : null;

        if (! $token instanceof ApiToken) {
            return response()->json(['message' => __('Missing or unknown API token.')], 401);
        }

        if ($token->isExpired()) {
            return response()->json(['message' => __('This API token has expired.')], 401);
        }

        if (! $token->last_used_at?->isAfter(now()->subMinute())) {
            ApiToken::query()->whereKey($token->id)->update(['last_used_at' => now()]);
        }

        $request->attributes->set('apiToken', $token);

        return $next($request);
    }
}
