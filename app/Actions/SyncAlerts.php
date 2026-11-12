<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEvent;
use App\Enums\AlertSeverity;
use App\Models\Alert;
use App\Models\Twin;
use Illuminate\Support\Facades\DB;

/**
 * Brings a twin's open alerts in line with what its gateway says is open.
 * An alert the gateway reports that isn't open here is raised; one open here
 * that it no longer reports has cleared; one that's both carries on, so a
 * gateway that restarts or repeats itself raises nothing twice. The
 * project's notification channels are told of each raise and clear.
 */
final readonly class SyncAlerts
{
    public function __construct(private NotifyAlert $notify) {}

    /**
     * @param  list<array{rule: string, asset: string, severity: AlertSeverity, message: string, value: float}>  $open
     * @return array{raised: list<Alert>, cleared: list<Alert>}
     */
    public function handle(Twin $twin, array $open): array
    {
        $changes = DB::transaction(function () use ($twin, $open): array {
            $now = now();
            $current = $twin->openAlerts()->lockForUpdate()->get()
                ->keyBy(fn (Alert $alert): string => $alert->rule."\n".$alert->asset);
            $reported = collect($open)->keyBy(fn (array $alert): string => $alert['rule']."\n".$alert['asset']);

            $raised = array_values($reported->diffKeys($current)->map(fn (array $alert): Alert => $twin->alerts()->create([
                ...$alert,
                'raised_at' => $now,
            ]))->all());

            $cleared = array_values($current->diffKeys($reported)->each(fn (Alert $alert): bool => $alert->update([
                'cleared_at' => $now,
            ]))->all());

            return ['raised' => $raised, 'cleared' => $cleared];
        });

        // Told once the change is saved, so a delivery never outruns it.
        foreach ($changes['raised'] as $alert) {
            $this->notify->handle($alert, AlertEvent::Raised);
        }

        foreach ($changes['cleared'] as $alert) {
            $this->notify->handle($alert, AlertEvent::Cleared);
        }

        return $changes;
    }
}
