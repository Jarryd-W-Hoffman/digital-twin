<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReadingHistory;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Requests\IndexTwinReadingsRequest;
use App\Models\Twin;
use Illuminate\Http\JsonResponse;

/**
 * A metric's recorded history, bucketed, for API tokens: the same answer
 * the twin's charts get.
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
}
