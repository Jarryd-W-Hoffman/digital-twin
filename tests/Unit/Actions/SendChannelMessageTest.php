<?php

declare(strict_types=1);

use App\Actions\SendChannelMessage;
use App\Enums\AlertEvent;
use App\Enums\AlertSeverity;
use App\Enums\IssueEvent;
use App\Enums\NotificationChannelKind;
use App\Mail\AlertMail;
use App\Mail\IssueMail;
use App\Models\Alert;
use App\Models\Issue;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\AlertMessage;
use App\Support\IssueMessage;
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

    resolve(SendChannelMessage::class)->handle($channel, AlertMessage::about($alert, AlertEvent::Raised));

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

    resolve(SendChannelMessage::class)->handle($channel, AlertMessage::about($alert, AlertEvent::Raised));

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
    $send = resolve(SendChannelMessage::class);

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

    resolve(SendChannelMessage::class)->handle($slack, AlertMessage::about($alert, AlertEvent::Raised));
    resolve(SendChannelMessage::class)->handle($teams, AlertMessage::about($alert, AlertEvent::Raised));

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
    $send = resolve(SendChannelMessage::class);
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

it('says an escalated alert is still waiting for someone', function (): void {
    Http::fake();
    $this->freezeSecond();
    $alert = alertOn('warning');
    $slack = NotificationChannel::factory()->for($alert->twin->project)->webhook(NotificationChannelKind::Slack, 'https://hooks.slack.com/services/x')->create();
    $teams = NotificationChannel::factory()->for($alert->twin->project)->webhook(NotificationChannelKind::Teams, 'https://x.webhook.office.com/x')->create();
    $message = AlertMessage::about($alert, AlertEvent::Escalated);

    resolve(SendChannelMessage::class)->handle($slack, $message);
    resolve(SendChannelMessage::class)->handle($teams, $message);

    expect($message->subject())->toBe('Not acknowledged: Warning: Seafood store is above its temperature limit (Harbour Depot)')
        ->and($message->timing())->toEndWith('still open and not acknowledged after 12 minutes')
        ->and($message->payload()['event'])->toBe('alert.escalated');
    Http::assertSent(fn (Request $request): bool => str_starts_with((string) ($request->data()['text'] ?? ''), ':rotating_light: *Not acknowledged'));
    Http::assertSent(fn (Request $request): bool => ($request->data()['attachments'][0]['content']['body'][0]['color'] ?? null) === 'Attention');
});

function issueOn(): Issue
{
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $twin->update(['name' => 'Harbour Depot']);
    $twin->project->update(['name' => 'Auckland sites']);

    return Issue::factory()->for($twin)->create(['title' => 'Gate sticks', 'due_on' => '2026-11-20']);
}

it('says what happened to an issue, by whom, and when it is due', function (IssueEvent $event, string $subject, string $mark, string $tone): void {
    $issue = issueOn();
    $issue->setRelation('assignee', User::factory()->make(['name' => 'Ana']));

    $by = User::factory()->make(['name' => 'Sam']);

    $message = IssueMessage::about($issue, $event, $by);

    expect($message->subject())->toBe($subject)
        ->and($message->detail())->toBe($event === IssueEvent::Closed ? 'Auckland sites · by Sam' : 'Auckland sites · by Sam · due 2026-11-20')
        ->and($message->mark())->toBe($mark)
        ->and($message->tone())->toBe($tone)
        ->and($message->event())->toBe('issue.'.$event->value)
        ->and($message->link())->toBe(route('twins.show', [$issue->twin, 'issue' => $issue->id]))
        ->and($message->linkLabel())->toBe('Open the issue');
})->with([
    [IssueEvent::Raised, 'Issue #1 raised: Gate sticks (Harbour Depot)', ':round_pushpin:', 'Accent'],
    [IssueEvent::Assigned, 'Issue #1 assigned to Ana: Gate sticks (Harbour Depot)', ':bust_in_silhouette:', 'Accent'],
    [IssueEvent::Closed, 'Issue #1 closed: Gate sticks (Harbour Depot)', ':white_check_mark:', 'Good'],
]);

it('carries an issue to a webhook, Slack and Teams', function (): void {
    Http::fake();
    $issue = issueOn();
    $project = $issue->twin->project;
    $webhook = NotificationChannel::factory()->for($project)->webhook(url: 'https://93.184.216.34/meridian')->create();
    $slack = NotificationChannel::factory()->for($project)->webhook(NotificationChannelKind::Slack, 'https://hooks.slack.com/services/x')->create();
    $teams = NotificationChannel::factory()->for($project)->webhook(NotificationChannelKind::Teams, 'https://x.webhook.office.com/x')->create();
    $message = IssueMessage::about($issue, IssueEvent::Raised, null);
    $send = resolve(SendChannelMessage::class);

    $send->handle($webhook, $message);
    $send->handle($slack, $message);
    $send->handle($teams, $message);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/meridian'
        && $request->header('X-Meridian-Event') === ['issue.raised']
        && $request['issue'] === ['id' => $issue->id, 'number' => 1, 'title' => 'Gate sticks', 'assignee' => null, 'dueOn' => '2026-11-20', 'alertId' => null]
        && $request['by'] === null
        && $request['twin'] === 'Harbour Depot');
    Http::assertSent(fn (Request $request): bool => ($request->data()['text'] ?? null) === sprintf(":round_pushpin: *Issue #1 raised: Gate sticks (Harbour Depot)*\nAuckland sites · due 2026-11-20\n<%s|Open the issue>", $message->url));
    Http::assertSent(fn (Request $request): bool => ($request->data()['attachments'][0]['content']['actions'][0]['title'] ?? null) === 'Open the issue');
});

it('emails an issue to a channel, or to whoever was given it', function (): void {
    $issue = issueOn();
    $issue->setRelation('assignee', User::factory()->make(['name' => 'Ana']));

    $message = IssueMessage::about($issue, IssueEvent::Assigned, User::factory()->make(['name' => 'Sam']));

    expect($message->mail()->render())->toContain('Issue #1 assigned to Ana: Gate sticks (Harbour Depot)')
        ->toContain('20 November 2026')
        ->toContain('notification channels')
        ->and(new IssueMail($message, toAssignee: true)->render())->toContain('Sam gave you the issue');

    $unassigned = IssueMessage::about(issueOn(), IssueEvent::Raised, null);
    expect($unassigned->subject())->toBe('Issue #1 raised: Gate sticks (Harbour Depot)')
        ->and(new IssueMail($unassigned, toAssignee: true)->render())->toContain('someone gave you the issue');
});
