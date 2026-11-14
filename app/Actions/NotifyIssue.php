<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\IssueEvent;
use App\Jobs\DeliverIssueNotification;
use App\Models\ApiToken;
use App\Models\Issue;
use App\Models\User;
use App\Support\IssueMessage;

/**
 * Tells the project's channels that take issue updates that an issue was
 * raised, assigned or closed. Issues aren't urgent, so a channel in its
 * quiet hours isn't told.
 */
final readonly class NotifyIssue
{
    public function handle(Issue $issue, IssueEvent $event, User|ApiToken|null $by): void
    {
        $channels = $issue->twin->project->notificationChannels()->where('notify_issues', true)->orderBy('name')->get();
        if ($channels->isEmpty()) {
            return;
        }

        $message = IssueMessage::about($issue, $event, $by);
        $now = now();

        foreach ($channels as $channel) {
            if (! $channel->isQuietAt($now)) {
                dispatch(new DeliverIssueNotification($channel, $message));
            }
        }
    }
}
