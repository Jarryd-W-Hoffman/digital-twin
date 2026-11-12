<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcknowledgeAlert;
use App\Models\Alert;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * An operator acknowledging one of a twin's alerts.
 */
final readonly class TwinAlertAcknowledgementController
{
    public function store(Twin $twin, Alert $alert, #[CurrentUser] User $user, AcknowledgeAlert $action): RedirectResponse
    {
        Gate::authorize('operate', $twin);

        $action->handle($alert, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert acknowledged.')]);

        return back();
    }
}
