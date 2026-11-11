<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEvent;
use App\Enums\AlertSeverity;
use App\Enums\NotificationChannelKind;
use App\Mail\AlertMail;
use App\Models\NotificationChannel;
use App\Support\AlertMessage;
use App\Support\WebhookGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Carries one message to one channel: an email, a signed JSON webhook, a
 * Slack message or a Teams card. Throws when it doesn't get there, with the
 * reason.
 */
final readonly class SendAlertNotification
{
    public function __construct(private WebhookGuard $guard) {}

    public function handle(NotificationChannel $channel, AlertMessage $message): void
    {
        if ($channel->kind === NotificationChannelKind::Email) {
            Mail::to($channel->addresses())->sendNow(new AlertMail($message));

            return;
        }

        $address = $this->guard->check($channel->kind, $channel->target);
        $body = json_encode(match ($channel->kind) {
            NotificationChannelKind::Slack => ['text' => $this->slack($message)],
            NotificationChannelKind::Teams => $this->teams($message),
            default => $message->payload(),
        }, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = $this->request($channel, $address)
            ->withHeaders($channel->kind === NotificationChannelKind::Webhook ? [
                'X-Meridian-Event' => $message->payload()['event'],
                'X-Meridian-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $channel->secret),
            ] : [])
            ->withBody($body, 'application/json')
            ->post($channel->target)
            ->throw();

        throw_if($response->redirect(), RuntimeException::class, __("The webhook answered with a redirect, which Meridian doesn't follow."));
    }

    /**
     * A short request that doesn't follow redirects (they could lead
     * anywhere), connecting to the address that was checked.
     */
    private function request(NotificationChannel $channel, ?string $address): PendingRequest
    {
        $request = Http::timeout(10)->connectTimeout(5)->withoutRedirecting();
        if ($address === null) {
            return $request;
        }

        $parts = parse_url($channel->target);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $port = is_array($parts) ? ($parts['port'] ?? (($parts['scheme'] ?? '') === 'http' ? 80 : 443)) : 443;

        return $request->withOptions(['curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $address)]]]);
    }

    private function slack(AlertMessage $message): string
    {
        $mark = match (true) {
            $message->event === AlertEvent::Cleared => ':white_check_mark:',
            $message->severity === AlertSeverity::Critical => ':red_circle:',
            $message->severity === AlertSeverity::Warning => ':large_orange_circle:',
            default => ':large_blue_circle:',
        };

        return sprintf("%s *%s*\n%s · %s\n<%s|%s>", $mark, $message->subject(), $message->project, $message->timing(), $message->url, __('Open the alert log'));
    }

    /**
     * An Adaptive Card, as a Teams workflow's "post to a channel when a
     * webhook request is received" expects.
     *
     * @return array<string, mixed>
     */
    private function teams(AlertMessage $message): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => $message->subject(), 'weight' => 'Bolder', 'wrap' => true, 'color' => $message->event === AlertEvent::Cleared ? 'Good' : ($message->severity === AlertSeverity::Critical ? 'Attention' : 'Warning')],
                        ['type' => 'TextBlock', 'text' => $message->project.' · '.$message->timing(), 'isSubtle' => true, 'wrap' => true],
                    ],
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => __('Open the alert log'), 'url' => $message->url]],
                ],
            ]],
        ];
    }
}
