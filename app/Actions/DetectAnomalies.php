<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AnomalyMonitor;

final readonly class DetectAnomalies
{
    public function __construct(private DetectAnomaly $detect) {}

    /**
     * Checks every monitor, every few minutes.
     */
    public function handle(): void
    {
        AnomalyMonitor::query()->with('twin')->lazyById()->each(fn (AnomalyMonitor $monitor) => $this->detect->handle($monitor));
    }
}
