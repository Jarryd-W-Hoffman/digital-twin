<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateNotificationChannel;
use App\Actions\DeleteNotificationChannel;
use App\Http\Requests\StoreNotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Where a project's alerts are sent.
 */
final readonly class ProjectNotificationChannelController
{
    public function store(StoreNotificationChannelRequest $request, Project $project, CreateNotificationChannel $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $channel = $action->handle($project, $request->channel());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $channel->name])]);
        if ($channel->secret !== null) {
            // Shown so the receiver can check signatures; it stays encrypted here.
            Inertia::flash('channelSecret', ['channelId' => $channel->id, 'secret' => $channel->secret]);
        }

        return to_route('projects.show', $project);
    }

    public function destroy(Project $project, NotificationChannel $notificationChannel, DeleteNotificationChannel $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $action->handle($notificationChannel);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $notificationChannel->name])]);

        return to_route('projects.show', $project);
    }
}
