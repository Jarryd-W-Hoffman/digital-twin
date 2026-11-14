<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\DeliverReport;
use App\Models\NotificationChannel;
use Carbon\CarbonImmutable;

/**
 * Sends each channel that takes reports the one that fell due last, at 7:00
 * in its time zone, once. A channel in its quiet hours is sent its report
 * when they end; one added after a report fell due waits for the next.
 */
final readonly class SendReports
{
    public function __construct(private BuildProjectReport $build) {}

    public function handle(): void
    {
        $now = CarbonImmutable::now();

        $channels = NotificationChannel::query()->whereNotNull('report')->with('project')->get();

        foreach ($channels as $channel) {
            $frequency = $channel->report;
            assert($frequency !== null);
            $due = $frequency->dueAt($now, $channel->timezone);

            if ($channel->created_at->greaterThan($due)
                || $channel->last_report_at?->greaterThanOrEqualTo($due) === true
                || $channel->isQuietAt($now)) {
                continue;
            }

            $channel->update(['last_report_at' => $now]);

            dispatch(new DeliverReport($channel, $this->build->handle($channel->project, $frequency, $frequency->since($due), $due, $channel->timezone)));
        }
    }
}
