<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
    public function index(IndexTwinReadingsRequest $request, ReadingHistory $history): JsonResponse
    {
        $link = ResolveShareLink::link($request);
        abort_unless($link->live, 404);

        return response()->json($history->handle(
            $link->twin,
            $request->string('asset')->value(),
            $request->string('metric')->value(),
            $request->integer('from'),
            $request->integer('to'),
            $request->integer('buckets', 300),
        ));
    }
}
