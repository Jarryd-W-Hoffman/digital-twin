<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TwinShareLink;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the share link a URL's token opens, before anything else: a token
 * that's unknown, revoked or expired is simply not found. Share pages aren't
 * indexed and send no referrer, so the token doesn't travel to map servers.
 */
final readonly class ResolveShareLink
{
    public static function link(Request $request): TwinShareLink
    {
        $link = $request->attributes->get('shareLink');
        assert($link instanceof TwinShareLink);

        return $link;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');
        $link = is_string($token) ? TwinShareLink::opening($token) : null;
        abort_unless($link instanceof TwinShareLink, 404);

        $request->attributes->set('shareLink', $link);

        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
