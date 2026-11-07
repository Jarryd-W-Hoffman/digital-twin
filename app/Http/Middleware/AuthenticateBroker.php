<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The broker's auth plugin can't send headers, so it proves itself with a
 * shared secret in the path. Without one configured, the routes don't exist.
 */
final readonly class AuthenticateBroker
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('meridian.mqtt.auth_secret');
        $given = $request->route('secret');
        $request->route()?->forgetParameter('secret');

        abort_unless(is_string($secret) && $secret !== '' && is_string($given) && hash_equals($secret, $given), 404);

        return $next($request);
    }
}
