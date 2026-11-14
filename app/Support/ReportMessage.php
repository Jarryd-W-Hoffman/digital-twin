<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReportFrequency;
use App\Mail\ReportMail;
use Carbon\CarbonImmutable;

/**
 * A project's report for a day or a week, sent to a channel like an alert:
 * a summary line per twin in chat, a table by email, and the figures as
 * data to a webhook.
 *
 * @phpstan-type Twin array{name: string, url: string, alerts: array{raised: int, critical: int, warning: int, acknowledged: int, medianAckMinutes: int|null, unacknowledged: int, open: int}, busiest: list<array{asset: string, alerts: int}>, issues: array{raised: int, closed: int, open: int, overdue: int}, overdue: list<array{number: int, title: string, assignee: string|null, dueOn: string, url: string}>}
 */
final readonly class ReportMessage implements ChannelMessage
{
    /**
     * @param  list<Twin>  $twins
     */
    public function __construct(
        public ReportFrequency $frequency,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
        public string $project,
        public string $url,
        public array $twins,
    ) {}

    /**
     * A median time to acknowledge, as people say it.
     */
    public static function minutes(?int $minutes): string
    {
        return match (true) {
            $minutes === null => __('nothing'),
            $minutes < 60 => __(':n min', ['n' => $minutes]),
            default => __(':h h :m min', ['h' => intdiv($minutes, 60), 'm' => $minutes % 60]),
        };
    }

    /**
     * The day or days it covers, in the channel's time zone: the day it
     * starts, or the first and last days of the week.
     */
    public function period(): string
    {
        $from = $this->from->setTimezone($this->timezone);

        return $this->frequency === ReportFrequency::Daily
            ? $from->format('l j F Y')
            : __(':from to :to', ['from' => $from->format('j F'), 'to' => $this->to->subDay()->setTimezone($this->timezone)->format('j F Y')]);
    }

    /**
     * The figures added up over every twin.
     *
     * @return array{alerts: int, critical: int, acknowledged: int, unacknowledged: int, openAlerts: int, raisedIssues: int, closedIssues: int, openIssues: int, overdue: int}
     */
    public function totals(): array
    {
        $totals = ['alerts' => 0, 'critical' => 0, 'acknowledged' => 0, 'unacknowledged' => 0, 'openAlerts' => 0, 'raisedIssues' => 0, 'closedIssues' => 0, 'openIssues' => 0, 'overdue' => 0];

        foreach ($this->twins as $twin) {
            $totals['alerts'] += $twin['alerts']['raised'];
            $totals['critical'] += $twin['alerts']['critical'];
            $totals['acknowledged'] += $twin['alerts']['acknowledged'];
            $totals['unacknowledged'] += $twin['alerts']['unacknowledged'];
            $totals['openAlerts'] += $twin['alerts']['open'];
            $totals['raisedIssues'] += $twin['issues']['raised'];
            $totals['closedIssues'] += $twin['issues']['closed'];
            $totals['openIssues'] += $twin['issues']['open'];
            $totals['overdue'] += $twin['issues']['overdue'];
        }

        return $totals;
    }

    public function subject(): string
    {
        return __(':every report for :project: :period', [
            'every' => $this->frequency->label(),
            'project' => $this->project,
            'period' => $this->period(),
        ]);
    }

    public function detail(): string
    {
        $totals = $this->totals();
        $lines = [
            __('Alerts: :raised raised (:critical critical), :acknowledged acknowledged, :unacknowledged not, :open open now', [
                'raised' => $totals['alerts'],
                'critical' => $totals['critical'],
                'acknowledged' => $totals['acknowledged'],
                'unacknowledged' => $totals['unacknowledged'],
                'open' => $totals['openAlerts'],
            ]),
            __('Issues: :raised raised, :closed closed, :open open, :overdue overdue', [
                'raised' => $totals['raisedIssues'],
                'closed' => $totals['closedIssues'],
                'open' => $totals['openIssues'],
                'overdue' => $totals['overdue'],
            ]),
        ];

        foreach ($this->twins as $twin) {
            $lines[] = __(':twin: :alerts alerts (:critical critical), median :ack to acknowledge; :open open issues, :overdue overdue', [
                'twin' => $twin['name'],
                'alerts' => $twin['alerts']['raised'],
                'critical' => $twin['alerts']['critical'],
                'ack' => self::minutes($twin['alerts']['medianAckMinutes']),
                'open' => $twin['issues']['open'],
                'overdue' => $twin['issues']['overdue'],
            ]);
        }

        return implode("\n", $lines);
    }

    public function link(): string
    {
        return $this->url;
    }

    public function linkLabel(): string
    {
        return __('Open the project');
    }

    public function event(): string
    {
        return 'report';
    }

    public function mark(): string
    {
        return ':bar_chart:';
    }

    public function tone(): string
    {
        $totals = $this->totals();

        return $totals['unacknowledged'] > 0 || $totals['overdue'] > 0 ? 'Warning' : 'Good';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => 'report',
            'every' => $this->frequency->value,
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'project' => $this->project,
            'url' => $this->url,
            'totals' => $this->totals(),
            'twins' => $this->twins,
        ];
    }

    public function mail(): ReportMail
    {
        return new ReportMail($this);
    }
}
