<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Requests\IndexAuditEventsRequest;
use App\Http\Resources\AuditEventResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The organisation's audit log for API tokens, newest first by cursor, so a
 * SIEM can collect it.
 */
final readonly class ApiAuditController
{
    public function index(IndexAuditEventsRequest $request): AnonymousResourceCollection
    {
        $token = AuthenticateApiToken::token($request);

        return AuditEventResource::collection($request->events($token->organisation)->cursorPaginate(50)->withQueryString());
    }
}
