<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordGatewayCheckIn;
use App\Models\Twin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What a gateway polls: the latest version of the twin its token belongs to.
 * Each call is also its check-in. Answers 304 while the version is unchanged.
 */
final readonly class GatewayDefinitionController
{
    public function show(Request $request, RecordGatewayCheckIn $checkIn): JsonResponse|Response
    {
        $token = $request->bearerToken();
        $twin = is_string($token) && $token !== ''
            ? Twin::query()->where('gateway_token_hash', hash('sha256', $token))->first()
            : null;

        if (! $twin instanceof Twin) {
            return response()->json(['message' => __('Missing or unknown gateway token.')], 401);
        }

        $checkIn->handle($twin, $request->header('X-Gateway-Url'));

        $latest = $twin->latestVersion()->firstOrFail();
        $etag = '"v'.$latest->number.'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->noContent(304)->setEtag('v'.$latest->number);
        }

        return response()->json([
            'twin' => ['id' => $twin->id, 'name' => $twin->name],
            'version' => $latest->number,
            'definition' => $latest->definition,
        ])->setEtag('v'.$latest->number);
    }
}
