<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ExpectedBand;
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
    public function index(IndexTwinReadingsRequest $request, Twin $twin, ReadingHistory $history, ExpectedBand $band): JsonResponse
    {
        Gate::authorize('view', $twin);

        $asset = $request->string('asset')->value();
        $metric = $request->string('metric')->value();
        $recorded = $history->handle($twin, $asset, $metric, $request->integer('from'), $request->integer('to'), $request->integer('buckets', 300));

        return response()->json([...$recorded, 'expected' => $band->handle($twin, $asset, $metric, $recorded['points'])]);
    }
}
