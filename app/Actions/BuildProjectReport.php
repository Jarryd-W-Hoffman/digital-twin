<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertSeverity;
use App\Enums\ReportFrequency;
use App\Models\Alert;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Twin;
use App\Support\ReportMessage;
use Carbon\CarbonImmutable;

/**
 * What happened in a project's twins over a period: the alerts raised and
 * how quickly they were acknowledged, the assets that raised most, and the
 * issues raised, closed, still open and overdue.
 */
final readonly class BuildProjectReport
{
    public function handle(Project $project, ReportFrequency $frequency, CarbonImmutable $from, CarbonImmutable $to, string $timezone): ReportMessage
    {
        $twins = array_values($project->twins()->orderBy('name')->orderBy('id')->get()
            ->map(fn (Twin $twin): array => $this->twin($twin, $from, $to))
            ->all());

        return new ReportMessage(
            frequency: $frequency,
            from: $from,
            to: $to,
            timezone: $timezone,
            project: $project->name,
            url: route('projects.show', $project),
            twins: $twins,
        );
    }

    /**
     * @return array{name: string, url: string, alerts: array{raised: int, critical: int, warning: int, acknowledged: int, medianAckMinutes: int|null, unacknowledged: int, open: int}, busiest: list<array{asset: string, alerts: int}>, issues: array{raised: int, closed: int, open: int, overdue: int}, overdue: list<array{number: int, title: string, assignee: string|null, dueOn: string, url: string}>}
     */
    private function twin(Twin $twin, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $alerts = $twin->alerts()
            ->where('raised_at', '>=', $from)
            ->where('raised_at', '<', $to)
            ->get(['id', 'asset', 'severity', 'raised_at', 'acknowledged_at']);

        $acknowledged = $alerts->filter(fn (Alert $alert): bool => $alert->acknowledged_at !== null);
        $minutes = $acknowledged
            ->map(fn (Alert $alert): float => max(0, $alert->raised_at->diffInSeconds($alert->acknowledged_at)) / 60)
            ->sort()
            ->values();

        $overdue = $twin->issues()
            ->whereNull('closed_at')
            ->whereNotNull('due_on')
            ->where('due_on', '<', today())
            ->with('assignee:id,name');

        return [
            'name' => $twin->name,
            'url' => route('twins.alerts.index', $twin),
            'alerts' => [
                'raised' => $alerts->count(),
                'critical' => $alerts->where('severity', AlertSeverity::Critical)->count(),
                'warning' => $alerts->where('severity', AlertSeverity::Warning)->count(),
                'acknowledged' => $acknowledged->count(),
                'medianAckMinutes' => $minutes->isEmpty() ? null : (int) round((float) $minutes->median()),
                'unacknowledged' => $alerts->count() - $acknowledged->count(),
                'open' => $twin->openAlerts()->count(),
            ],
            'busiest' => array_values($alerts->countBy('asset')
                ->sortDesc()
                ->take(3)
                ->map(fn (int $count, string $asset): array => ['asset' => $asset, 'alerts' => $count])
                ->all()),
            'issues' => [
                'raised' => $twin->issues()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
                'closed' => $twin->issues()->where('closed_at', '>=', $from)->where('closed_at', '<', $to)->count(),
                'open' => $twin->issues()->whereNull('closed_at')->count(),
                'overdue' => (clone $overdue)->count(),
            ],
            'overdue' => array_values($overdue->orderBy('due_on')->orderBy('number')->limit(5)->get()
                ->map(fn (Issue $issue): array => [
                    'number' => $issue->number,
                    'title' => $issue->title,
                    'assignee' => $issue->assignee?->name,
                    'dueOn' => $issue->due_on?->format('Y-m-d') ?? '',
                    'url' => route('twins.show', [$twin, 'issue' => $issue->id]),
                ])
                ->all()),
        ];
    }
}
