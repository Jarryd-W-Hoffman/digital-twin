<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordGatewayCheckIn;
use App\Http\Middleware\AuthenticateGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What a gateway polls: the latest version of the twin its token belongs to,
 * and how to log in to the broker for its telemetry. Each call is also its
 * check-in. Answers 304 while the version is unchanged.
 */
final readonly class GatewayDefinitionController
{
    public function show(Request $request, RecordGatewayCheckIn $checkIn): JsonResponse|Response
    {
        $twin = AuthenticateGateway::twin($request);
        $checkIn->handle($twin, $request->header('X-Gateway-Url'), $request->header('X-Gateway-Mqtt'));

        $latest = $twin->latestVersion()->firstOrFail();
        $etag = '"v'.$latest->number.'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->noContent(304)->setEtag('v'.$latest->number);
        }

        return response()->json([
            'twin' => ['id' => $twin->id, 'name' => $twin->name],
            'version' => $latest->number,
            'definition' => $latest->definition,
            'mqtt' => ['username' => $twin->mqttUsername(), 'topicPrefix' => $twin->project->topicPrefix()],
        ])->setEtag('v'.$latest->number);
    }
}
