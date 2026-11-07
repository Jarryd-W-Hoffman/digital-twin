<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Twin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gateways authenticate with their twin's token as a bearer token. The twin
 * it belongs to is left on the request for the controller.
 */
final readonly class AuthenticateGateway
{
    /**
     * The twin whose token the request carried.
     */
    public static function twin(Request $request): Twin
    {
        $twin = $request->attributes->get('twin');
        assert($twin instanceof Twin);

        return $twin;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $twin = is_string($token) && $token !== ''
            ? Twin::query()->where('gateway_token_hash', hash('sha256', $token))->first()
            : null;

        if (! $twin instanceof Twin) {
            return response()->json(['message' => __('Missing or unknown gateway token.')], 401);
        }

        $request->attributes->set('twin', $twin);

        return $next($request);
    }
}
