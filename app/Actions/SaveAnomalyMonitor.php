<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertSeverity;
use App\Enums\AnomalySensitivity;
use App\Enums\AuditAction;
use App\Models\AnomalyMonitor;
use App\Models\Twin;
use App\Models\User;

final readonly class SaveAnomalyMonitor
{
    public function __construct(private LearnAnomalyBaseline $learn, private DetectAnomaly $detect, private RecordAuditEvent $audit) {}

    /**
     * Starts watching an asset's metric, or changes how, and learns its band
     * from the history already recorded. Turning a check off clears what it
     * had open.
     *
     * @param  array{sensitivity: AnomalySensitivity, severity: AlertSeverity, bands: bool, flatline_hours: int|null}  $settings
     */
    public function handle(Twin $twin, ?User $user, string $asset, string $metric, array $settings): AnomalyMonitor
    {
        $monitor = $twin->anomalyMonitors()->firstOrNew(['asset' => $asset, 'metric' => $metric]);
        $isNew = ! $monitor->exists;
        $monitor->forceFill([...$settings, 'created_by' => $monitor->created_by ?? $user?->id])->save();

        if ($isNew) {
            $this->learn->handle($monitor);
        }

        $this->detect->clear($monitor, band: ! $settings['bands'], flat: $settings['flatline_hours'] === null);

        $this->audit->handle(AuditAction::AnomalyMonitorSaved, $twin, $asset.'.'.$metric, [
            'sensitivity' => $settings['bands'] ? $settings['sensitivity']->value : 'off',
            'flatlineHours' => $settings['flatline_hours'],
            'severity' => $settings['severity']->value,
        ]);

        return $monitor;
    }
}
