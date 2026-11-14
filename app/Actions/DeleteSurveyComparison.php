<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\SurveyComparison;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteSurveyComparison
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Deletes the comparison and its images; the surveys stay in the library.
     */
    public function handle(SurveyComparison $comparison): void
    {
        Storage::disk(config()->string('meridian.assets_disk'))->deleteDirectory(dirname($comparison->imageKey(1)));
        $comparison->delete();

        $this->audit->handle(AuditAction::SurveyComparisonDeleted, $comparison->twin, $comparison->name);
    }
}
