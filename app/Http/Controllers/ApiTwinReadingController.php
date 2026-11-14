<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReadingHistory;
use App\Actions\RecordReadings;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Requests\IndexTwinReadingsRequest;
use App\Http\Requests\StoreApiReadingsRequest;
use App\Models\Twin;
use Illuminate\Http\JsonResponse;

/**
 * A metric's recorded history, bucketed, for API tokens: the same answer
 * the twin's charts get. Tokens that may write add readings, for systems
 * that measure without a gateway.
 */
final readonly class ApiTwinReadingController
{
    public function index(IndexTwinReadingsRequest $request, Twin $twin, ReadingHistory $history): JsonResponse
    {
        abort_unless(AuthenticateApiToken::token($request)->reaches($twin), 404);

        return response()->json(['data' => $history->handle(
            $twin,
            $request->string('asset')->value(),
            $request->string('metric')->value(),
            $request->integer('from'),
            $request->integer('to'),
            $request->integer('buckets', 300),
        )]);
    }

    public function store(StoreApiReadingsRequest $request, Twin $twin, RecordReadings $action): JsonResponse
    {
        return response()->json(['stored' => $action->handle($twin, $request->readings())], 202);
    }
}
