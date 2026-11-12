<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SendAlertNotification;
use App\Enums\AlertNotificationStatus;
use App\Models\AlertNotification;
use App\Support\AlertMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Delivers one notification to its channel, trying three times over a few
 * minutes, and records how it went on the notification and the channel.
 */
#[Backoff([10, 60, 300])]
#[Timeout(60)]
#[Tries(3)]
final class DeliverAlertNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AlertNotification $notification)
    {
        $this->onConnection(config()->string('meridian.notifications.connection') ?: null);
    }

    public function handle(SendAlertNotification $send): void
    {
        $channel = $this->notification->channel;
        if ($channel === null) {
            $this->notification->update(['status' => AlertNotificationStatus::Failed, 'error' => __('The channel was deleted.'), 'attempted_at' => now()]);

            return;
        }

        $send->handle($channel, AlertMessage::about($this->notification->alert, $this->notification->event));

        $this->notification->update(['status' => AlertNotificationStatus::Sent, 'error' => null, 'attempted_at' => now()]);
        $channel->update(['last_sent_at' => now(), 'last_error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = mb_substr($exception?->getMessage() ?: __('Delivery failed.'), 0, 500);

        $this->notification->update(['status' => AlertNotificationStatus::Failed, 'error' => $error, 'attempted_at' => now()]);
        $this->notification->channel?->update(['last_error' => $error]);
    }
}
