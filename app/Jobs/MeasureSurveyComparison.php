<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\MeasureSurveys;
use App\Enums\SurveyComparisonStatus;
use App\Exceptions\ConversionFailed;
use App\Models\SurveyComparison;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use JsonException;
use Throwable;

/**
 * Measures a survey comparison on the "conversions" queue, where the
 * converter has GDAL and meridian-volume. Tried once: the reason it failed
 * is shown on the comparison, and saving it again retries.
 */
#[Timeout(3600)]
#[Tries(1)]
final class MeasureSurveyComparison implements ShouldQueue
{
    use Queueable;

    public function __construct(public SurveyComparison $comparison)
    {
        $this->onConnection('conversions')->onQueue('conversions');
    }

    public function handle(MeasureSurveys $measure): void
    {
        try {
            $measure->handle($this->comparison);
        } catch (ConversionFailed $conversionFailed) {
            $this->comparison->update(['status' => SurveyComparisonStatus::Failed, 'error' => $conversionFailed->getMessage()]);
        } catch (JsonException) {
            $this->comparison->update(['status' => SurveyComparisonStatus::Failed, 'error' => __('meridian-volume gave no summary.')]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->comparison->update([
            'status' => SurveyComparisonStatus::Failed,
            'error' => __('Measuring stopped unexpectedly. Save the comparison again to retry.'),
        ]);
    }
}
