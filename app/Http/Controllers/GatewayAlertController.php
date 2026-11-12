<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SyncAlerts;
use App\Http\Middleware\AuthenticateGateway;
use App\Http\Requests\StoreGatewayAlertsRequest;
use Illuminate\Http\JsonResponse;

/**
 * Where a gateway reports which of its twin's alerts are open, whenever that
 * changes and on a heartbeat.
 */
final readonly class GatewayAlertController
{
    public function store(StoreGatewayAlertsRequest $request, SyncAlerts $action): JsonResponse
    {
        $changes = $action->handle(AuthenticateGateway::twin($request), $request->open());

        return response()->json([
            'raised' => count($changes['raised']),
            'cleared' => count($changes['cleared']),
        ]);
    }
}
