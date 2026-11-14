<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\SurveyComparisonStatus;
use App\Jobs\MeasureSurveyComparison;
use App\Models\SurveyComparison;
use App\Models\Twin;
use App\Models\User;
use InvalidArgumentException;

final readonly class SaveSurveyComparison
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Compares surveys of the ground inside an area of a twin, or changes a
     * comparison; a new area or new surveys are measured again on the
     * conversions queue, a new name isn't.
     *
     * @param  list<array{0: float, 1: float}>  $area  Longitude and latitude.
     * @param  list<string>  $surveys  Ids of the project's surveys, oldest first.
     */
    public function handle(Twin $twin, ?User $user, ?SurveyComparison $comparison, string $name, array $area, array $surveys): SurveyComparison
    {
        throw_if(count($surveys) < 2, InvalidArgumentException::class, 'Two surveys or more are needed.');
        throw_unless(
            $twin->project->surveys()->whereKey($surveys)->count() === count(array_unique($surveys)),
            InvalidArgumentException::class,
            "Every survey must be terrain or a point cloud with heights, in the twin's project.",
        );
        $comparison ??= $twin->surveyComparisons()->make(['created_by' => $user?->id]);
        $measure = $this->changesWhatIsMeasured($comparison, $area, $surveys);
        $comparison->forceFill(['name' => $name, 'area' => $area, 'surveys' => $surveys]);

        if ($measure) {
            $comparison->forceFill(['status' => SurveyComparisonStatus::Measuring, 'results' => null, 'error' => null]);
        }

        $comparison->save();

        if ($measure) {
            dispatch(new MeasureSurveyComparison($comparison));
        }

        $this->audit->handle(AuditAction::SurveyComparisonSaved, $twin, $name, ['surveys' => count($surveys)]);

        return $comparison;
    }

    /**
     * Whether it's new, or its area or surveys differ from these.
     *
     * @param  list<array{0: float, 1: float}>  $area
     * @param  list<string>  $surveys
     */
    private function changesWhatIsMeasured(SurveyComparison $comparison, array $area, array $surveys): bool
    {
        return ! $comparison->exists
            || array_map(fn (array $point): array => [(float) $point[0], (float) $point[1]], $comparison->area) !== $area
            || $comparison->surveys !== $surveys;
    }
}
