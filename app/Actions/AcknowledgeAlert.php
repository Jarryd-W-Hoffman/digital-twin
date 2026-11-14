<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Alert;
use App\Models\ApiToken;
use App\Models\User;
use App\Support\Actor;

/**
 * Records that someone has seen an alert and is on it. The first to
 * acknowledge it is kept.
 */
final readonly class AcknowledgeAlert
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Alert $alert, User|ApiToken $user): void
    {
        $acknowledged = Alert::query()
            ->whereKey($alert->id)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now(), 'acknowledged_by' => Actor::columns($user)[0], 'acknowledged_by_api' => Actor::columns($user)[1]]);

        if ($acknowledged > 0) {
            $this->audit->handle(AuditAction::AlertAcknowledged, $alert->twin, $alert->message, ['asset' => $alert->asset, 'severity' => $alert->severity->value]);
        }
    }
}
