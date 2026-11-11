<?php

declare(strict_types=1);

use App\Enums\NotificationChannelKind;
use App\Support\WebhookGuard;

it('refuses webhooks that could reach a private network', function (string $url, string $reason): void {
    expect(fn (): ?string => resolve(WebhookGuard::class)->check(NotificationChannelKind::Webhook, $url))
        ->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'plain http' => ['http://93.184.216.34/hook', 'The URL must start with https://.'],
    'no host' => ['https:///hook', 'The URL must start with https://.'],
    'not a URL' => ['hooks', 'The URL must start with https://.'],
    'a private address' => ['https://10.0.0.5/hook', '10.0.0.5 is on a private network'],
    'loopback' => ['https://127.0.0.1:8000/hook', '127.0.0.1 is on a private network'],
    'localhost' => ['https://localhost/hook', 'localhost is on a private network'],
    'cloud metadata' => ['https://169.254.169.254/latest', '169.254.169.254 is on a private network'],
    'IPv6 loopback' => ['https://[::1]/hook', '::1 is on a private network'],
    'nowhere' => ['https://nowhere.invalid/hook', "nowhere.invalid can't be found."],
]);

it('lets a public webhook through, and says which address to connect to', function (): void {
    expect(resolve(WebhookGuard::class)->check(NotificationChannelKind::Webhook, 'https://93.184.216.34/hook'))->toBe('93.184.216.34');
});

it('keeps Slack and Teams to their own domains', function (): void {
    $guard = resolve(WebhookGuard::class);

    expect($guard->check(NotificationChannelKind::Slack, 'https://hooks.slack.com/services/T0/B0/x'))->toBeNull()
        ->and($guard->check(NotificationChannelKind::Teams, 'https://prod-01.westus.logic.azure.com/workflows/x'))->toBeNull()
        ->and($guard->check(NotificationChannelKind::Teams, 'https://contoso.webhook.office.com/webhookb2/x'))->toBeNull()
        ->and(fn (): ?string => $guard->check(NotificationChannelKind::Slack, 'https://hooks.slack.com.evil.example/x'))->toThrow(InvalidArgumentException::class, 'A Slack webhook URL starts with https://hooks.slack.com/.')
        ->and(fn (): ?string => $guard->check(NotificationChannelKind::Teams, 'https://example.com/x'))->toThrow(InvalidArgumentException::class, 'A Teams webhook URL comes from a Teams workflow');
});

it('allows private addresses and http when told to, for development', function (): void {
    config(['meridian.notifications.allow_private_webhooks' => true]);

    expect(resolve(WebhookGuard::class)->check(NotificationChannelKind::Webhook, 'http://127.0.0.1:9000/hook'))->toBe('127.0.0.1');
});
