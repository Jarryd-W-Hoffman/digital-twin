<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordReadings;
use App\Http\Middleware\AuthenticateGateway;
use App\Http\Requests\StoreGatewayReadingsRequest;
use Illuminate\Http\JsonResponse;

/**
 * Where a gateway sends the readings it took, from MQTT or POST /ingest,
 * for the twin's history.
 */
final readonly class GatewayReadingController
{
    public function store(StoreGatewayReadingsRequest $request, RecordReadings $action): JsonResponse
    {
        $stored = $action->handle(AuthenticateGateway::twin($request), $request->readings());

        return response()->json(['stored' => $stored], 202);
    }
}
