<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ExpectedBand;
use App\Actions\ReadingHistory;
use App\Http\Middleware\ResolveShareLink;
use App\Http\Requests\IndexTwinReadingsRequest;
use Illuminate\Http\JsonResponse;

/**
 * A shared twin's recorded history, for its charts, when the link shows
 * real readings at all.
 */
final readonly class SharedTwinReadingController
{
    public function index(IndexTwinReadingsRequest $request, ReadingHistory $history, ExpectedBand $band): JsonResponse
    {
        $link = ResolveShareLink::link($request);
        abort_unless($link->live, 404);

        $asset = $request->string('asset')->value();
        $metric = $request->string('metric')->value();
        $recorded = $history->handle($link->twin, $asset, $metric, $request->integer('from'), $request->integer('to'), $request->integer('buckets', 300));

        return response()->json([...$recorded, 'expected' => $band->handle($link->twin, $asset, $metric, $recorded['points'])]);
    }
}
