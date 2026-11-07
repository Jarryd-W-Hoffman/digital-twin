<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReadingHistory;
use App\Http\Requests\IndexTwinReadingsRequest;
use App\Models\Twin;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A twin's recorded readings for one metric, bucketed for a chart.
 */
final readonly class TwinReadingController
{
    public function index(IndexTwinReadingsRequest $request, Twin $twin, ReadingHistory $history): JsonResponse
    {
        Gate::authorize('view', $twin);

        return response()->json($history->handle(
            $twin,
            $request->string('asset')->value(),
            $request->string('metric')->value(),
            $request->integer('from'),
            $request->integer('to'),
            $request->integer('buckets', 300),
        ));
    }
}
