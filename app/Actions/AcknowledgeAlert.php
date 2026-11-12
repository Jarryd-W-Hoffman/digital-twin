<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Alert;
use App\Models\User;

/**
 * Records that someone has seen an alert and is on it. The first to
 * acknowledge it is kept.
 */
final readonly class AcknowledgeAlert
{
    public function handle(Alert $alert, User $user): void
    {
        Alert::query()
            ->whereKey($alert->id)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now(), 'acknowledged_by' => $user->id]);
    }
}
