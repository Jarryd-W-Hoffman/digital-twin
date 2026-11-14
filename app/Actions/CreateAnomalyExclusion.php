<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\AnomalyExclusion;
use App\Models\AnomalyMonitor;
use App\Models\Twin;
use App\Models\User;
use Carbon\CarbonInterface;

final readonly class CreateAnomalyExclusion
{
    public function __construct(private LearnAnomalyBaseline $learn, private RecordAuditEvent $audit) {}

    /**
     * Leaves a period out of what the twin's monitors learn, and checks
     * nothing while it lasts. They learn again without it at once.
     */
    public function handle(Twin $twin, ?User $user, CarbonInterface $startsAt, CarbonInterface $endsAt, ?string $note): AnomalyExclusion
    {
        $exclusion = $twin->anomalyExclusions()->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'note' => $note,
            'created_by' => $user?->id,
        ]);

        $twin->anomalyMonitors()->each(fn (AnomalyMonitor $monitor): AnomalyMonitor => $this->learn->handle($monitor));

        $this->audit->handle(AuditAction::AnomalyExclusionAdded, $twin, $note, ['from' => $startsAt->toIso8601String(), 'to' => $endsAt->toIso8601String()]);

        return $exclusion;
    }
}
