<?php

declare(strict_types=1);

use App\Actions\SendAlertNotification;
use App\Enums\AlertEvent;
use App\Enums\AlertSeverity;
use App\Enums\NotificationChannelKind;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\NotificationChannel;
use App\Support\AlertMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function alertOn(string $severity = 'critical', bool $cleared = false): Alert
{
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $twin->update(['name' => 'Harbour Depot']);

    return Alert::factory()->for($twin)->create([
        'severity' => AlertSeverity::from($severity),
        'raised_at' => now()->subMinutes(12),
        'cleared_at' => $cleared ? now() : null,
    ]);
}

it('emails every address', function (): void {
    Mail::fake();
    $alert = alertOn();
    $channel = NotificationChannel::factory()->for($alert->twin->project)->create(['target' => 'ops@example.com, oncall@example.com']);

    resolve(SendAlertNotification::class)->handle($channel, AlertMessage::about($alert, AlertEvent::Raised));

    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->hasTo('ops@example.com')
        && $mail->hasTo('oncall@example.com')
        && $mail->hasSubject('Critical: Seafood store is above its temperature limit (Harbour Depot)'));
});

it('writes an email anyone can act on', function (): void {
    $alert = alertOn('warning', cleared: true);

    $html = new AlertMail(AlertMessage::about($alert, AlertEvent::Cleared))->render();

    expect($html)->toContain('Cleared: Seafood store is above its temperature limit (Harbour Depot)')
        ->toContain('cleared after 12 minutes')
        ->toContain(route('twins.alerts.index', $alert->twin))
        ->toContain('Open the alert log');
});

it('posts a signed webhook with the alert as data', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response(null, 204)]);
    $alert = alertOn();
    $channel = NotificationChannel::factory()->for($alert->twin->project)->webhook(url: 'https://93.184.216.34/meridian')->create();

    resolve(SendAlertNotification::class)->handle($channel, AlertMessage::about($alert, AlertEvent::Raised));

    Http::assertSent(function (Request $request) use ($alert): bool {
        $body = $request->body();

        return $request->url() === 'https://93.184.216.34/meridian'
            && $request->header('X-Meridian-Event') === ['alert.raised']
            && $request->header('X-Meridian-Signature') === ['sha256='.hash_hmac('sha256', $body, 'whsec_test')]
            && $request['alert']['id'] === $alert->id
            && $request['alert']['severity'] === 'critical'
            && $request['twin'] === 'Harbour Depot'
            && $request['url'] === route('twins.alerts.index', $alert->twin);
    });
});

it('posts Slack a message, and Teams a card', function (): void {
    Http::fake();
    $alert = alertOn('warning', cleared: true);
    $project = $alert->twin->project;
    $slack = NotificationChannel::factory()->for($project)->webhook(NotificationChannelKind::Slack, 'https://hooks.slack.com/services/T0/B0/x')->create();
    $teams = NotificationChannel::factory()->for($project)->webhook(NotificationChannelKind::Teams, 'https://prod-01.westus.logic.azure.com/workflows/x')->create();
    $send = resolve(SendAlertNotification::class);

    $send->handle($slack, AlertMessage::about($alert, AlertEvent::Raised));
    $send->handle($teams, AlertMessage::about($alert, AlertEvent::Cleared));

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://hooks.slack.com/')
        && str_starts_with((string) $request['text'], ':large_orange_circle: *Warning: Seafood store is above its temperature limit (Harbour Depot)*')
        && str_contains((string) $request['text'], '|Open the alert log>')
        && $request->header('X-Meridian-Signature') === []);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'logic.azure.com')
        && $request['type'] === 'message'
        && $request['attachments'][0]['content']['body'][0]['text'] === 'Cleared: Seafood store is above its temperature limit (Harbour Depot)'
        && $request['attachments'][0]['content']['body'][0]['color'] === 'Good'
        && $request['attachments'][0]['content']['actions'][0]['url'] === route('twins.alerts.index', $alert->twin));
});

it('marks Slack by severity', function (string $severity, string $mark): void {
    Http::fake();
    $alert = alertOn($severity);
    $slack = NotificationChannel::factory()->for($alert->twin->project)->webhook(NotificationChannelKind::Slack, 'https://hooks.slack.com/services/x')->create();
    $teams = NotificationChannel::factory()->for($alert->twin->project)->webhook(NotificationChannelKind::Teams, 'https://x.webhook.office.com/x')->create();

    resolve(SendAlertNotification::class)->handle($slack, AlertMessage::about($alert, AlertEvent::Raised));
    resolve(SendAlertNotification::class)->handle($teams, AlertMessage::about($alert, AlertEvent::Raised));

    Http::assertSent(fn (Request $request): bool => str_starts_with((string) ($request->data()['text'] ?? ''), $mark));
    Http::assertSent(fn (Request $request): bool => ($request['attachments'][0]['content']['body'][0]['color'] ?? null) === ($severity === 'critical' ? 'Attention' : 'Warning'));
})->with([
    ['critical', ':red_circle:'],
    ['info', ':large_blue_circle:'],
]);

it('throws when the receiver refuses, redirects, or is on a private network', function (): void {
    Http::fake([
        'https://93.184.216.34/refuses' => Http::response('nope', 500),
        'https://93.184.216.34/moves' => Http::response(null, 302, ['Location' => 'http://10.0.0.1/']),
    ]);
    $alert = alertOn();
    $send = resolve(SendAlertNotification::class);
    $message = AlertMessage::about($alert, AlertEvent::Raised);
    $channel = fn (string $url): NotificationChannel => NotificationChannel::factory()->for($alert->twin->project)->webhook(url: $url)->create();

    expect(fn () => $send->handle($channel('https://93.184.216.34/refuses'), $message))->toThrow(RequestException::class)
        ->and(fn () => $send->handle($channel('https://93.184.216.34/moves'), $message))->toThrow(RuntimeException::class, "The webhook answered with a redirect, which Meridian doesn't follow.")
        ->and(fn () => $send->handle($channel('https://10.1.2.3/hook'), $message))->toThrow(InvalidArgumentException::class, 'private network');
    Http::assertSentCount(2);
});

it('describes a test without a twin', function (): void {
    $channel = NotificationChannel::factory()->create(['name' => 'Night shift']);

    $message = AlertMessage::test($channel);

    expect($message->subject())->toBe('Info: A test from Meridian: Night shift works. (No twin)')
        ->and($message->payload()['event'])->toBe('test')
        ->and($message->payload()['alert']['id'])->toBeNull()
        ->and($message->url)->toBe(route('projects.show', $channel->project))
        ->and(new AlertMail($message)->render())->toContain('Open the project');
});
