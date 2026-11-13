<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SendChannelMessage;
use App\Models\NotificationChannel;
use App\Support\IssueMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Delivers one issue update to a channel, trying three times over a few
 * minutes, and records how it went on the channel. Dropped if the channel
 * is deleted first.
 */
#[Backoff([10, 60, 300])]
#[DeleteWhenMissingModels]
#[Timeout(60)]
#[Tries(3)]
final class DeliverIssueNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public NotificationChannel $channel, public IssueMessage $message)
    {
        $this->onConnection(config()->string('meridian.notifications.connection') ?: null);
    }

    public function handle(SendChannelMessage $send): void
    {
        $send->handle($this->channel, $this->message);

        $this->channel->update(['last_sent_at' => now(), 'last_error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->channel->update(['last_error' => mb_substr($exception?->getMessage() ?: __('Delivery failed.'), 0, 500)]);
    }
}
