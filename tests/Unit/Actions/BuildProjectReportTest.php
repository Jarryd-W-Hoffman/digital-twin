<?php

declare(strict_types=1);

use App\Actions\BuildProjectReport;
use App\Enums\AlertSeverity;
use App\Enums\ReportFrequency;
use App\Models\Alert;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Twin;
use App\Models\User;
use Carbon\CarbonImmutable;

it("adds up a project's alerts and issues over the period, per twin", function (): void {
    $this->freezeSecond();
    $to = CarbonImmutable::now();
    $from = $to->subDay();
    $project = Project::factory()->create(['name' => 'Pilot sites']);
    $depot = Twin::factory()->inProject($project)->create(['name' => 'Harbour Depot']);
    $quiet = Twin::factory()->inProject($project)->create(['name' => 'Airport']);
    Twin::factory()->create();

    $raised = $to->subHours(5);
    Alert::factory()->for($depot)->create(['asset' => 'store', 'severity' => AlertSeverity::Critical, 'raised_at' => $raised, 'acknowledged_at' => $raised->addMinutes(4)]);
    Alert::factory()->for($depot)->cleared()->create(['asset' => 'store', 'severity' => AlertSeverity::Warning, 'raised_at' => $raised, 'acknowledged_at' => $raised->addMinutes(10)]);
    Alert::factory()->for($depot)->cleared()->create(['asset' => 'store', 'severity' => AlertSeverity::Warning, 'raised_at' => $raised, 'acknowledged_at' => $raised->addMinutes(90)]);
    Alert::factory()->for($depot)->cleared()->create(['asset' => 'pump', 'severity' => AlertSeverity::Info, 'raised_at' => $raised]);
    Alert::factory()->for($depot)->create(['asset' => 'pump', 'raised_at' => $to->subDays(2)]);

    $ana = User::factory()->create(['name' => 'Ana']);
    Issue::factory()->for($depot)->create(['created_at' => $to->subHours(2)]);
    Issue::factory()->for($depot)->create(['created_at' => $to->subDays(3), 'closed_at' => $to->subHour()]);
    $late = Issue::factory()->for($depot)->assigned($ana, '-3 days')->create(['title' => 'Door seal', 'created_at' => $to->subDays(9)]);
    Issue::factory()->for($depot)->assigned(null, '-1 day')->create(['created_at' => $to->subDays(9)]);
    Issue::factory()->for($depot)->assigned(null, '+3 days')->create(['created_at' => $to->subDays(9)]);

    $report = resolve(BuildProjectReport::class)->handle($project, ReportFrequency::Daily, $from, $to, 'Pacific/Auckland');

    expect($report->project)->toBe('Pilot sites')
        ->and($report->url)->toBe(route('projects.show', $project))
        ->and($report->timezone)->toBe('Pacific/Auckland')
        ->and(array_column($report->twins, 'name'))->toBe(['Airport', 'Harbour Depot'])
        ->and($report->twins[0]['alerts']['medianAckMinutes'])->toBeNull()
        ->and($report->twins[0]['busiest'])->toBe([])
        ->and($report->twins[1])->toBe([
            'name' => 'Harbour Depot',
            'url' => route('twins.alerts.index', $depot),
            'alerts' => ['raised' => 4, 'critical' => 1, 'warning' => 2, 'acknowledged' => 3, 'medianAckMinutes' => 10, 'unacknowledged' => 1, 'open' => 2],
            'busiest' => [['asset' => 'store', 'alerts' => 3], ['asset' => 'pump', 'alerts' => 1]],
            'issues' => ['raised' => 1, 'closed' => 1, 'open' => 4, 'overdue' => 2],
            'overdue' => [
                ['number' => $late->number, 'title' => 'Door seal', 'assignee' => 'Ana', 'dueOn' => today()->subDays(3)->format('Y-m-d'), 'url' => route('twins.show', [$depot, 'issue' => $late->id])],
                ['number' => $late->number + 1, 'title' => 'Cracked slab by the loading bay', 'assignee' => $report->twins[1]['overdue'][1]['assignee'], 'dueOn' => today()->subDay()->format('Y-m-d'), 'url' => $report->twins[1]['overdue'][1]['url']],
            ],
        ]);
    expect($quiet->exists)->toBeTrue();
});
