<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SendChannelMessage;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Support\AlertMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Throwable;

/**
 * Sends a channel a test message straight away, and says how it went.
 */
final readonly class ProjectNotificationChannelTestController
{
    public function store(Project $project, NotificationChannel $notificationChannel, SendChannelMessage $send): RedirectResponse
    {
        Gate::authorize('update', $project);

        try {
            $send->handle($notificationChannel, AlertMessage::test($notificationChannel));
        } catch (Throwable $throwable) {
            $error = mb_substr($throwable->getMessage(), 0, 500);
            $notificationChannel->update(['last_error' => $error]);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The test to :name failed: :error', ['name' => $notificationChannel->name, 'error' => $error])]);

            return to_route('projects.show', $project);
        }

        $notificationChannel->update(['last_sent_at' => now(), 'last_error' => null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test sent to :name.', ['name' => $notificationChannel->name])]);

        return to_route('projects.show', $project);
    }
}
