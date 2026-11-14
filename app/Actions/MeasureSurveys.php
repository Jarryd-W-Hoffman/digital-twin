<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SurveyComparisonStatus;
use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use App\Models\SurveyComparison;
use App\Support\Converter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Measures a survey comparison with meridian-volume, in the converter:
 *
 *     copy each survey's heights (surface.tif) to a working folder
 *     for each survey after the first:
 *         meridian-volume <the one before> <it> <image> --area <lon/lat ring> --cell <m>
 *         keep its cut, fill and areas, and its image of where
 *
 * A survey that has gone from the library, or a pair that doesn't overlap
 * in the area, fails the comparison with the reason.
 */
final readonly class MeasureSurveys
{
    public function __construct(private Converter $converter) {}

    public function handle(SurveyComparison $comparison): SurveyComparison
    {
        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $work = storage_path('app/comparisons/'.$comparison->id);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);

        try {
            $surveys = $comparison->twin->project->surveys()->whereKey($comparison->surveys)->get()->keyBy('id');
            $surfaces = [];

            foreach ($comparison->surveys as $index => $id) {
                $survey = $surveys->get($id);
                throw_unless($survey instanceof ProjectAsset, ConversionFailed::class, __('A survey in the comparison is no longer in the library.'));

                $surfaces[$index] = $work.'/'.$index.'.tif';
                $this->converter->download($disk, $survey->directory.'/'.ProjectAsset::SURFACE, $surfaces[$index]);
            }

            $disk->deleteDirectory(dirname($comparison->imageKey(1)));
            $results = [];

            for ($pair = 1; $pair < count($surfaces); $pair++) {
                $image = $work.'/'.$pair.'.png';
                $output = $this->converter->run([
                    config()->string('meridian.surveys.tool'), $surfaces[$pair - 1], $surfaces[$pair], $image,
                    '--area', (string) json_encode($comparison->area),
                    '--cell', (string) config()->float('meridian.surveys.cell_m'),
                ], __(':before to :after could not be measured', [
                    'before' => $surveys->get($comparison->surveys[$pair - 1])?->name,
                    'after' => $surveys->get($comparison->surveys[$pair])?->name,
                ]), config()->integer('meridian.surveys.timeout_minutes'));

                throw_unless(File::exists($image), ConversionFailed::class, __('meridian-volume drew no image.'));
                $disk->put($comparison->imageKey($pair), File::get($image));

                $lines = array_filter(explode("\n", mb_trim($output)));
                /** @var array{cut: float, fill: float, net: float, areaM2: float, coveredM2: float, cutM2: float, fillM2: float, deepestCut: float, highestFill: float, cell: float, bounds: list<float>} $summary */
                $summary = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
                $results[] = ['before' => $comparison->surveys[$pair - 1], 'after' => $comparison->surveys[$pair], ...$summary];
            }

            $comparison->update(['status' => SurveyComparisonStatus::Ready, 'results' => $results, 'error' => null, 'measured_at' => now()]);

            return $comparison;
        } catch (Throwable $throwable) {
            $disk->deleteDirectory(dirname($comparison->imageKey(1)));

            throw $throwable;
        } finally {
            File::deleteDirectory($work);
        }
    }
}
