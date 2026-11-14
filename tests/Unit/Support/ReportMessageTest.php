<?php

declare(strict_types=1);

use App\Actions\SendChannelMessage;
use App\Enums\NotificationChannelKind;
use App\Enums\ReportFrequency;
use App\Mail\ReportMail;
use App\Models\NotificationChannel;
use App\Support\ReportMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $overrides  Figures for Harbour Depot.
 */
function sampleReport(ReportFrequency $frequency = ReportFrequency::Weekly, array $overrides = []): ReportMessage
{
    $to = CarbonImmutable::parse('2026-11-15T18:00:00Z');

    return new ReportMessage(
        frequency: $frequency,
        from: $frequency->since($to),
        to: $to,
        timezone: 'Pacific/Auckland',
        project: 'Pilot sites',
        url: 'https://meridian.test/projects/p',
        twins: [
            [
                'name' => 'Harbour Depot',
                'url' => 'https://meridian.test/twins/t/alerts',
                'alerts' => ['raised' => 5, 'critical' => 1, 'warning' => 3, 'acknowledged' => 4, 'medianAckMinutes' => 75, 'unacknowledged' => 1, 'open' => 1],
                'busiest' => [['asset' => 'store', 'alerts' => 3]],
                'issues' => ['raised' => 2, 'closed' => 1, 'open' => 3, 'overdue' => 1],
                'overdue' => [['number' => 4, 'title' => 'Door seal', 'assignee' => 'Ana', 'dueOn' => '2026-11-10', 'url' => 'https://meridian.test/twins/t?issue=i']],
                ...$overrides,
            ],
            [
                'name' => 'Airport',
                'url' => 'https://meridian.test/twins/a/alerts',
                'alerts' => ['raised' => 0, 'critical' => 0, 'warning' => 0, 'acknowledged' => 0, 'medianAckMinutes' => null, 'unacknowledged' => 0, 'open' => 0],
                'busiest' => [],
                'issues' => ['raised' => 0, 'closed' => 0, 'open' => 0, 'overdue' => 0],
                'overdue' => [],
            ],
        ],
    );
}

it('names the period in the channel time zone', function (): void {
    expect(sampleReport()->subject())->toBe('Weekly report for Pilot sites: 9 November to 15 November 2026')
        ->and(sampleReport(ReportFrequency::Daily)->subject())->toBe('Daily report for Pilot sites: Sunday 15 November 2026');
});

it('sums the twins and says it in lines', function (): void {
    expect(sampleReport()->totals())->toBe(['alerts' => 5, 'critical' => 1, 'acknowledged' => 4, 'unacknowledged' => 1, 'openAlerts' => 1, 'raisedIssues' => 2, 'closedIssues' => 1, 'openIssues' => 3, 'overdue' => 1])
        ->and(sampleReport()->detail())->toBe(implode("\n", [
            'Alerts: 5 raised (1 critical), 4 acknowledged, 1 not, 1 open now',
            'Issues: 2 raised, 1 closed, 3 open, 1 overdue',
            'Harbour Depot: 5 alerts (1 critical), median 1 h 15 min to acknowledge; 3 open issues, 1 overdue',
            'Airport: 0 alerts (0 critical), median nothing to acknowledge; 0 open issues, 0 overdue',
        ]))
        ->and(ReportMessage::minutes(4))->toBe('4 min')
        ->and(sampleReport()->link())->toBe('https://meridian.test/projects/p')
        ->and(sampleReport()->linkLabel())->toBe('Open the project')
        ->and(sampleReport()->event())->toBe('report')
        ->and(sampleReport()->mark())->toBe(':bar_chart:');
});

it('warns while something is unacknowledged or overdue', function (): void {
    $calm = ['alerts' => ['raised' => 1, 'critical' => 0, 'warning' => 1, 'acknowledged' => 1, 'medianAckMinutes' => 2, 'unacknowledged' => 0, 'open' => 0], 'issues' => ['raised' => 0, 'closed' => 0, 'open' => 0, 'overdue' => 0]];

    expect(sampleReport()->tone())->toBe('Warning')
        ->and(sampleReport(overrides: $calm)->tone())->toBe('Good');
});

it('gives a webhook the figures', function (): void {
    expect(sampleReport()->payload())->toMatchArray([
        'event' => 'report',
        'every' => 'weekly',
        'from' => '2026-11-08T18:00:00+00:00',
        'to' => '2026-11-15T18:00:00+00:00',
        'project' => 'Pilot sites',
        'url' => 'https://meridian.test/projects/p',
        'totals' => sampleReport()->totals(),
    ])->and(sampleReport()->payload()['twins'])->toBe(sampleReport()->twins);
});

it('emails a table per twin', function (): void {
    $mail = sampleReport()->mail();
    $html = $mail->render();

    expect($mail)->toBeInstanceOf(ReportMail::class)
        ->and($mail->envelope()->subject)->toBe(sampleReport()->subject())
        ->and($html)->toContain('Harbour Depot')
        ->and($html)->toContain('1 h 15 min')
        ->and($html)->toContain('Most alerts: store (3)')
        ->and($html)->toContain('#4 Door seal')
        ->and($html)->toContain('due 2026-11-10, Ana')
        ->and($html)->toContain('Open the project');
});

it('goes to Slack and webhooks like any message', function (): void {
    Http::fake();
    $slack = NotificationChannel::factory()->webhook(NotificationChannelKind::Slack, 'https://hooks.slack.com/services/x')->create();
    $hook = NotificationChannel::factory()->webhook(url: 'https://93.184.216.34/meridian')->create();

    resolve(SendChannelMessage::class)->handle($slack, sampleReport());
    resolve(SendChannelMessage::class)->handle($hook, sampleReport());

    Http::assertSent(fn (Request $request): bool => str_starts_with((string) data_get($request->data(), 'text'), ':bar_chart: *Weekly report for Pilot sites'));
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Meridian-Event', 'report') && data_get($request->data(), 'twins.0.name') === 'Harbour Depot');
});
