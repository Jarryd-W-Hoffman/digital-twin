<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a write from a token that may only read, before anything in the
 * request is checked.
 */
final readonly class EnsureApiTokenWrites
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! AuthenticateApiToken::token($request)->access->canWrite()) {
            return response()->json(['message' => __('This API token may only read.')], 403);
        }

        return $next($request);
    }
}
