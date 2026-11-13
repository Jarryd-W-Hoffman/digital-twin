<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationChannelKind;
use App\Models\NotificationChannel;
use App\Support\ChannelMessage;
use App\Support\WebhookGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Carries one message, about an alert or an issue, to one channel: an
 * email, a signed JSON webhook, a Slack message or a Teams card. Throws
 * when it doesn't get there, with the reason.
 */
final readonly class SendChannelMessage
{
    public function __construct(private WebhookGuard $guard) {}

    public function handle(NotificationChannel $channel, ChannelMessage $message): void
    {
        if ($channel->kind === NotificationChannelKind::Email) {
            Mail::to($channel->addresses())->sendNow($message->mail());

            return;
        }

        $address = $this->guard->check($channel->kind, $channel->target);
        $body = json_encode(match ($channel->kind) {
            NotificationChannelKind::Slack => ['text' => sprintf("%s *%s*\n%s\n<%s|%s>", $message->mark(), $message->subject(), $message->detail(), $message->link(), $message->linkLabel())],
            NotificationChannelKind::Teams => $this->teams($message),
            default => $message->payload(),
        }, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = $this->request($channel, $address)
            ->withHeaders($channel->kind === NotificationChannelKind::Webhook ? [
                'X-Meridian-Event' => $message->event(),
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

    /**
     * An Adaptive Card, as a Teams workflow's "post to a channel when a
     * webhook request is received" expects.
     *
     * @return array<string, mixed>
     */
    private function teams(ChannelMessage $message): array
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
                        ['type' => 'TextBlock', 'text' => $message->subject(), 'weight' => 'Bolder', 'wrap' => true, 'color' => $message->tone()],
                        ['type' => 'TextBlock', 'text' => $message->detail(), 'isSubtle' => true, 'wrap' => true],
                    ],
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => $message->linkLabel(), 'url' => $message->link()]],
                ],
            ]],
        ];
    }
}
