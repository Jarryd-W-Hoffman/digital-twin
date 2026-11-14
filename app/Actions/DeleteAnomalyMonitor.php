<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\AnomalyMonitor;

final readonly class DeleteAnomalyMonitor
{
    public function __construct(private DetectAnomaly $detect, private RecordAuditEvent $audit) {}

    /**
     * Stops watching the metric, clearing what it had open.
     */
    public function handle(AnomalyMonitor $monitor): void
    {
        $this->detect->clear($monitor);
        $monitor->delete();

        $this->audit->handle(AuditAction::AnomalyMonitorRemoved, $monitor->twin, $monitor->asset.'.'.$monitor->metric);
    }
}
