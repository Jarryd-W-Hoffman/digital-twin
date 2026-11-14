<?php

declare(strict_types=1);

use App\Actions\SendReports;
use App\Enums\ReportFrequency;
use App\Jobs\DeliverReport;
use App\Models\NotificationChannel;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

it('sends each channel the report that fell due, once', function (): void {
    Queue::fake();
    $this->travelTo('2026-11-16T06:00:00Z');
    $daily = NotificationChannel::factory()->reports()->create(['timezone' => 'UTC']);
    $weekly = NotificationChannel::factory()->reports(ReportFrequency::Weekly)->create(['timezone' => 'UTC']);
    NotificationChannel::factory()->create(['timezone' => 'UTC']);

    $this->travelTo('2026-11-16T07:02:00Z');
    resolve(SendReports::class)->handle();

    Queue::assertPushed(DeliverReport::class, 2);
    Queue::assertPushed(fn (DeliverReport $job): bool => $job->channel->is($daily)
        && $job->report->frequency === ReportFrequency::Daily
        && $job->report->from->toIso8601String() === '2026-11-15T07:00:00+00:00'
        && $job->report->to->toIso8601String() === '2026-11-16T07:00:00+00:00');
    Queue::assertPushed(fn (DeliverReport $job): bool => $job->channel->is($weekly)
        && $job->report->from->toIso8601String() === '2026-11-09T07:00:00+00:00');
    expect($daily->refresh()->last_report_at?->equalTo(now()))->toBeTrue();

    $this->travel(5)->minutes();
    resolve(SendReports::class)->handle();
    Queue::assertPushed(DeliverReport::class, 2);

    $this->travelTo('2026-11-17T07:00:00Z');
    resolve(SendReports::class)->handle();
    Queue::assertPushed(DeliverReport::class, 3);
});

it('waits for the next report after a channel is added, and for its quiet hours to end', function (): void {
    Queue::fake();
    $this->travelTo('2026-11-16T08:00:00Z');
    NotificationChannel::factory()->reports()->create(['timezone' => 'UTC']);

    resolve(SendReports::class)->handle();
    Queue::assertNothingPushed();

    $quiet = NotificationChannel::factory()->reports()->create(['timezone' => 'Pacific/Auckland', 'quiet_from' => '06:00', 'quiet_until' => '09:00', 'created_at' => now()->subWeek()]);
    $this->travelTo('2026-11-16T18:30:00Z');
    resolve(SendReports::class)->handle();
    Queue::assertNotPushed(fn (DeliverReport $job): bool => $job->channel->is($quiet));

    $this->travelTo('2026-11-16T20:00:00Z');
    resolve(SendReports::class)->handle();
    Queue::assertPushed(fn (DeliverReport $job): bool => $job->channel->is($quiet));
});

it('runs every five minutes', function (): void {
    $event = collect(resolve(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'send-reports');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('*/5 * * * *');

    Queue::fake();
    $this->travelTo('2026-11-16T06:00:00Z');
    NotificationChannel::factory()->reports()->create(['timezone' => 'UTC']);
    $this->travelTo('2026-11-16T07:00:00Z');
    $event->run(app());

    Queue::assertPushed(DeliverReport::class, 1);
});
