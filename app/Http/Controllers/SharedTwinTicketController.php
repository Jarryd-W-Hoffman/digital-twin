<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IssueGatewayTicket;
use App\Http\Middleware\ResolveShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a share page follows the twin live: the gateway's address with a
 * ticket good for ten minutes, asked for again on each reconnect. Null when
 * the link doesn't show it live or the gateway is offline.
 */
final readonly class SharedTwinTicketController
{
    public function show(Request $request, IssueGatewayTicket $tickets): JsonResponse
    {
        $link = ResolveShareLink::link($request);
        $twin = $link->twin;

        if (! $link->live || $twin->gateway_token_hash === null || ! $twin->gatewayOnline() || $twin->gateway_url === null) {
            return response()->json(['url' => null]);
        }

        $separator = str_contains($twin->gateway_url, '?') ? '&' : '?';

        return response()->json(['url' => $twin->gateway_url.$separator.'ticket='.$tickets->forShare($link)]);
    }
}
