<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildProjectReport;
use App\Actions\SendChannelMessage;
use App\Enums\ReportFrequency;
use App\Models\NotificationChannel;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Throwable;

/**
 * Sends a channel the project's report for the day or week up to now,
 * straight away, to see what it looks like or to catch up.
 */
final readonly class ProjectNotificationChannelReportController
{
    public function store(Project $project, NotificationChannel $notificationChannel, BuildProjectReport $build, SendChannelMessage $send): RedirectResponse
    {
        Gate::authorize('update', $project);

        $frequency = $notificationChannel->report ?? ReportFrequency::Daily;
        $now = CarbonImmutable::now();

        try {
            $send->handle($notificationChannel, $build->handle($project, $frequency, $frequency->since($now), $now, $notificationChannel->timezone));
        } catch (Throwable $throwable) {
            $error = mb_substr($throwable->getMessage(), 0, 500);
            $notificationChannel->update(['last_error' => $error]);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The report to :name failed: :error', ['name' => $notificationChannel->name, 'error' => $error])]);

            return to_route('projects.show', $project);
        }

        $notificationChannel->update(['last_sent_at' => now(), 'last_error' => null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report sent to :name.', ['name' => $notificationChannel->name])]);

        return to_route('projects.show', $project);
    }
}
