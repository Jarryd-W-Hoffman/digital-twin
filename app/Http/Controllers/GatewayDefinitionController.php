<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordGatewayCheckIn;
use App\Http\Middleware\AuthenticateGateway;
use App\Models\OpcUaServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What a gateway polls: the latest version of the twin its token belongs to,
 * how to log in to the broker for its telemetry, and the project's OPC UA
 * servers and whether operators' setpoints may be written to them. Each call
 * is also its check-in. Answers 304 while all of that is unchanged.
 */
final readonly class GatewayDefinitionController
{
    public function show(Request $request, RecordGatewayCheckIn $checkIn): JsonResponse|Response
    {
        $twin = AuthenticateGateway::twin($request);
        $checkIn->handle(
            $twin,
            $request->header('X-Gateway-Url'),
            $request->header('X-Gateway-Mqtt'),
            $request->header('X-Gateway-Opcua'),
        );

        $latest = $twin->latestVersion()->firstOrFail();
        $servers = $twin->project->opcUaServers()->orderBy('name')->get()
            ->map(fn (OpcUaServer $server): array => $server->forGateway())
            ->all();
        $tag = 'v'.$latest->number
            .($servers === [] ? '' : '-'.mb_substr(hash('sha256', (string) json_encode($servers)), 0, 16))
            .($twin->opcua_writes ? '-w' : '');

        if ($request->header('If-None-Match') === '"'.$tag.'"') {
            return response()->noContent(304)->setEtag($tag);
        }

        return response()->json([
            'twin' => ['id' => $twin->id, 'name' => $twin->name],
            'version' => $latest->number,
            'definition' => $latest->definition,
            'mqtt' => ['username' => $twin->mqttUsername(), 'topicPrefix' => $twin->project->topicPrefix()],
            'opcua' => ['servers' => $servers, 'writes' => $twin->opcua_writes],
        ])->setEtag($tag);
    }
}
