<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\AnomalyExclusion;
use App\Models\AnomalyMonitor;

final readonly class DeleteAnomalyExclusion
{
    public function __construct(private LearnAnomalyBaseline $learn, private RecordAuditEvent $audit) {}

    /**
     * Counts the period as normal again; the monitors learn with it at once.
     */
    public function handle(AnomalyExclusion $exclusion): void
    {
        $twin = $exclusion->twin;
        $exclusion->delete();

        $twin->anomalyMonitors()->each(fn (AnomalyMonitor $monitor): AnomalyMonitor => $this->learn->handle($monitor));

        $this->audit->handle(AuditAction::AnomalyExclusionRemoved, $twin, $exclusion->note, ['from' => $exclusion->starts_at->toIso8601String(), 'to' => $exclusion->ends_at->toIso8601String()]);
    }
}
