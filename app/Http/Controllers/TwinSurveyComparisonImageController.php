<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SurveyComparison;
use App\Models\Twin;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the image of where the ground changed between a survey and the one
 * before it, to those who can see the twin.
 */
final readonly class TwinSurveyComparisonImageController
{
    public function show(Twin $twin, SurveyComparison $surveyComparison, int $pair): StreamedResponse
    {
        Gate::authorize('view', $twin);

        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $key = $surveyComparison->imageKey($pair);

        abort_unless($disk->fileExists($key), 404);

        return $disk->response($key, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
