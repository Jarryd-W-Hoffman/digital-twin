<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\CreateScimToken;
use App\Actions\RecordAuditEvent;
use App\Exceptions\ScimError;
use App\Models\SsoConnection;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * An identity provider provisioning people sends its connection's SCIM
 * token as a bearer token. What it changes is recorded as "Provisioning".
 */
final readonly class AuthenticateScimToken
{
    public static function connection(Request $request): SsoConnection
    {
        $connection = $request->attributes->get('scimConnection');
        assert($connection instanceof SsoConnection);

        return $connection;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $connection = is_string($bearer) && str_starts_with($bearer, CreateScimToken::PREFIX)
            ? SsoConnection::query()->where('scim_token_hash', hash('sha256', $bearer))->first()
            : null;

        throw_unless($connection instanceof SsoConnection, ScimError::class, 401, __('Missing or unknown provisioning token.'));

        if (! $connection->scim_token_used_at?->isAfter(now()->subMinute())) {
            SsoConnection::query()->whereKey($connection->id)->update(['scim_token_used_at' => now()]);
        }

        $request->attributes->set('scimConnection', $connection);
        Context::addHidden(RecordAuditEvent::API_TOKEN, __('Provisioning'));

        return $next($request);
    }
}
