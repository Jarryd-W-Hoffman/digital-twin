<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordOpcUaWrite;
use App\Http\Middleware\AuthenticateGateway;
use App\Http\Requests\StoreGatewayOpcUaWriteRequest;
use Illuminate\Http\JsonResponse;

/**
 * Where a gateway reports each setpoint it wrote to an OPC UA node, for the
 * twin's audit trail.
 */
final readonly class GatewayOpcUaWriteController
{
    public function store(StoreGatewayOpcUaWriteRequest $request, RecordOpcUaWrite $action): JsonResponse
    {
        $write = $action->handle(AuthenticateGateway::twin($request), $request->write());

        return response()->json(['id' => $write->id], 201);
    }
}
