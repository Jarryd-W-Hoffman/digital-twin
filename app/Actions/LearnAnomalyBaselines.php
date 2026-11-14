<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AnomalyMonitor;

final readonly class LearnAnomalyBaselines
{
    public function __construct(private LearnAnomalyBaseline $learn) {}

    /**
     * Relearns every monitor's band, each night, from its latest history.
     */
    public function handle(): void
    {
        AnomalyMonitor::query()->with('twin')->lazyById()->each(fn (AnomalyMonitor $monitor): AnomalyMonitor => $this->learn->handle($monitor));
    }
}
