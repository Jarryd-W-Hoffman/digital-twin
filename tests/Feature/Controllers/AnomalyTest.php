<?php

declare(strict_types=1);

use App\Actions\DetectAnomalies;
use App\Actions\DetectAnomaly;
use App\Actions\IssueGatewayToken;
use App\Actions\LearnAnomalyBaseline;
use App\Actions\LearnAnomalyBaselines;
use App\Enums\AlertSeverity;
use App\Enums\AnomalySensitivity;
use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\Alert;
use App\Models\AnomalyExclusion;
use App\Models\AnomalyMonitor;
use App\Models\AuditEvent;
use App\Models\Reading;
use App\Models\Twin;
use App\Models\TwinShareLink;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Wednesday 11 November 2026, 03:00 UTC: 15:00 at the sample depot (UTC+12).
 */
beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-11 03:00:00', 'UTC')));

/**
 * Readings of the seafood store, from a moment until now, every so often.
 *
 * @param  callable(CarbonImmutable): float  $value
 */
function storeReadings(Twin $twin, CarbonImmutable $from, callable $value, int $everyMinutes = 60, string $metric = 'tempC'): void
{
    $rows = [];
    for ($at = $from; $at->lessThan(now()); $at = $at->addMinutes($everyMinutes)) {
        $rows[] = ['twin_id' => $twin->id, 'asset' => 'store', 'metric' => $metric, 'value' => $value($at), 'recorded_at' => $at];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        Reading::query()->insert($chunk);
    }
}

/**
 * A monitor with a flat band of -19 ±0.5 (medium: unusual beyond ±1.5).
 */
function watchedStore(array $state = []): AnomalyMonitor
{
    [, $organisation] = member();

    return AnomalyMonitor::factory()->for(twinIn($organisation))->learned()->create($state);
}

it("learns what's usual for each local hour, weekdays and weekends apart, and its spread", function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    // Warmer in the local afternoon on weekdays, when the doors open; steady at weekends.
    storeReadings($twin, CarbonImmutable::now()->subDays(21), function (CarbonImmutable $at): float {
        $local = $at->addHours(12);

        return -19.0 + ($local->hour >= 12 && $local->hour < 17 && ! $local->isWeekend() ? 3.0 : 0.0) + ($local->day % 2 === 0 ? 0.2 : -0.2);
    });
    $monitor = AnomalyMonitor::factory()->for($twin)->create();

    resolve(LearnAnomalyBaseline::class)->handle($monitor);

    $monitor->refresh();
    expect($monitor->ready())->toBeTrue()
        ->and($monitor->baseline['days'])->toEqual(21.0)
        ->and($monitor->learned_at?->equalTo(now()))->toBeTrue()
        ->and($monitor->baseline['slots']['wd-15'])->toEqual(['median' => -15.8, 'spread' => 0.158, 'n' => 15])
        ->and($monitor->baseline['slots']['we-15']['median'])->toEqual(-19.2)
        ->and($monitor->baseline['slots']['wd-3']['n'])->toBe(15)
        ->and($monitor->baseline['slots']['we-3']['n'])->toBe(6)
        ->and($monitor->baseline['slots']['all-3']['n'])->toBe(21)
        ->and($monitor->expectedAt(now(), 12))->toBe(['median' => -15.8, 'spread' => 0.158])
        ->and($monitor->expectedAt(CarbonImmutable::parse('2026-11-14 03:00:00', 'UTC'), 12))->toBe(['median' => -19.2, 'spread' => 0.192]);
});

it('takes the spread from how far hours stray from usual, keeps it off zero for a metric that never moves, and learns nothing from no history', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    storeReadings($twin, CarbonImmutable::now()->subDays(3), fn (): float => -20.0);
    storeReadings($twin, CarbonImmutable::now()->subDays(3), fn (CarbonImmutable $at): float => $at->addHours(12)->day % 2 === 0 ? 60.0 : 70.0, metric: 'humidity');
    $steady = AnomalyMonitor::factory()->for($twin)->create();
    $varied = AnomalyMonitor::factory()->for($twin)->create(['metric' => 'humidity']);
    $empty = AnomalyMonitor::factory()->for($twin)->create(['metric' => 'kW']);

    resolve(LearnAnomalyBaseline::class)->handle($steady);
    resolve(LearnAnomalyBaseline::class)->handle($varied);
    resolve(LearnAnomalyBaseline::class)->handle($empty);

    expect($steady->refresh()->baseline['slots']['wd-15'])->toEqual(['median' => -20.0, 'spread' => 0.2, 'n' => 2])
        ->and($steady->baseline['days'])->toEqual(3.0)
        ->and($steady->ready())->toBeFalse()
        ->and($steady->expectedAt(now(), 12))->toBeNull()
        ->and($varied->refresh()->baseline['slots']['wd-15'])->toEqual(['median' => 65.0, 'spread' => 7.413, 'n' => 2])
        ->and($empty->refresh()->baseline)->toEqual(['days' => 0.0, 'slots' => []]);
});

it('leaves excluded periods out of what it learns', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    // A week of defrosting, a fortnight ago, well above anything usual.
    $defrost = [CarbonImmutable::now()->subDays(16), CarbonImmutable::now()->subDays(9)];
    storeReadings($twin, CarbonImmutable::now()->subDays(21), fn (CarbonImmutable $at): float => $at->between(...$defrost) ? 5.0 : -19.0);
    $monitor = AnomalyMonitor::factory()->for($twin)->create();

    resolve(LearnAnomalyBaseline::class)->handle($monitor);
    expect($monitor->refresh()->baseline['slots']['wd-15']['median'])->not->toBe(-19.0);

    AnomalyExclusion::factory()->for($twin)->create(['starts_at' => $defrost[0], 'ends_at' => $defrost[1]]);
    AnomalyExclusion::factory()->for($twin)->create(['starts_at' => now()->subDays(40), 'ends_at' => now()->subDays(30)]);
    resolve(LearnAnomalyBaseline::class)->handle($monitor);

    expect($monitor->refresh()->baseline['slots']['wd-15'])->toEqual(['median' => -19.0, 'spread' => 0.19, 'n' => 10])
        ->and($monitor->baseline['slots']['all-15']['n'])->toBe(14);
});

it('falls back to every day at an hour with few weekend hours, and knows nothing at an hour with few of either', function (): void {
    $monitor = watchedStore();
    $slots = $monitor->baseline['slots'];
    $slots['wd-15'] = ['median' => 1.0, 'spread' => 1.0, 'n' => 3];
    $slots['all-15'] = ['median' => 2.0, 'spread' => 1.0, 'n' => 9];
    $slots['wd-16'] = ['median' => 1.0, 'spread' => 1.0, 'n' => 3];
    $slots['all-16'] = ['median' => 2.0, 'spread' => 1.0, 'n' => 3];
    unset($slots['wd-17'], $slots['all-17']);
    $monitor->forceFill(['baseline' => ['days' => 21.0, 'slots' => $slots]])->save();

    expect($monitor->expectedAt(now(), 12))->toBe(['median' => 2.0, 'spread' => 1.0])
        ->and($monitor->expectedAt(now()->addHour(), 12))->toBeNull()
        ->and($monitor->expectedAt(now()->addHours(2), 12))->toBeNull()
        ->and($monitor->expectedAt(now()->addHours(2), 10))->toBe(['median' => 2.0, 'spread' => 1.0]);
});

it('raises an alert when the last half hour is unusual for the hour, holds it near the edge, and clears it once usual', function (): void {
    $monitor = watchedStore(['severity' => AlertSeverity::Critical]);
    $twin = $monitor->twin;
    $detect = resolve(DetectAnomaly::class);

    storeReadings($twin, CarbonImmutable::now()->subMinutes(30), fn (): float => -16.5, 5);
    $detect->handle($monitor);
    $detect->handle($monitor);

    $alert = Alert::query()->sole();
    expect($alert)->rule->toBe('anomaly:tempC')->asset->toBe('store')->severity->toBe(AlertSeverity::Critical)
        ->value->toBe(-16.5)
        ->message->toBe('Seafood store tempC is -16.5, usually -19 ±0.5 at this hour')
        ->and($alert->raised_at->equalTo(now()))->toBeTrue();

    // Still unusual enough to hold, though not to raise: 2.4 spreads out.
    $this->travel(30)->minutes();
    storeReadings($twin, CarbonImmutable::now()->subMinutes(30), fn (): float => -17.8, 5);
    $detect->handle($monitor);
    expect($alert->refresh()->cleared_at)->toBeNull();

    $this->travel(30)->minutes();
    storeReadings($twin, CarbonImmutable::now()->subMinutes(30), fn (): float => -18.6, 5);
    $detect->handle($monitor);
    expect($alert->refresh()->cleared_at?->equalTo(now()))->toBeTrue();

    $this->travel(30)->minutes();
    storeReadings($twin, CarbonImmutable::now()->subMinutes(30), fn (): float => -17.8, 5);
    $detect->handle($monitor);
    expect(Alert::query()->count())->toBe(1);
});

it('checks nothing without recent readings, while still learning, with bands off, or during an excluded period', function (): void {
    $quiet = watchedStore();
    resolve(DetectAnomaly::class)->handle($quiet);

    $learning = watchedStore(['baseline' => ['days' => 5.0, 'slots' => []]]);
    $off = watchedStore(['bands' => false]);
    $paused = watchedStore();
    AnomalyExclusion::factory()->for($paused->twin)->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

    foreach ([$learning, $off, $paused] as $monitor) {
        storeReadings($monitor->twin, CarbonImmutable::now()->subMinutes(30), fn (): float => 5.0, 5);
        resolve(DetectAnomaly::class)->handle($monitor);
    }

    expect(Alert::query()->count())->toBe(0);
});

it("raises an alert when a reading hasn't changed for hours, and clears it when it moves", function (): void {
    $monitor = watchedStore(['flatline_hours' => 3, 'bands' => false]);
    $twin = $monitor->twin;
    $detect = resolve(DetectAnomaly::class);

    // Only the last hour stuck isn't three hours of it.
    storeReadings($twin, CarbonImmutable::now()->subHour(), fn (): float => -19.25, 10);
    $detect->handle($monitor);
    expect(Alert::query()->count())->toBe(0);

    storeReadings($twin, CarbonImmutable::now()->subHours(3), fn (): float => -19.25, 10);
    $detect->handle($monitor);
    $alert = Alert::query()->sole();
    expect($alert)->rule->toBe('anomaly:tempC:flat')->value->toBe(-19.25)
        ->message->toBe("Seafood store tempC hasn't changed from -19.3 in 3 hours");

    $this->travel(10)->minutes();
    storeReadings($twin, CarbonImmutable::now()->subMinute(), fn (): float => -19.0);
    $detect->handle($monitor);
    expect($alert->refresh()->cleared_at)->not->toBeNull();

    // A reading stopped altogether isn't stuck: the gateway's status says so.
    $this->travel(5)->hours();
    $detect->handle($monitor);
    expect(Alert::query()->count())->toBe(1);
});

it('names an asset the definition no longer has by its id, and rounds large values whole', function (): void {
    $monitor = watchedStore(['asset' => 'retired']);
    $monitor->forceFill(['baseline' => ['days' => 21.0, 'slots' => array_map(fn (array $slot): array => ['median' => 1200.0, 'spread' => 10.0, 'n' => 10], $monitor->baseline['slots'])]])->save();
    Reading::query()->insert(['twin_id' => $monitor->twin_id, 'asset' => 'retired', 'metric' => 'tempC', 'value' => 1260.4, 'recorded_at' => now()->subMinute()]);

    resolve(DetectAnomaly::class)->handle($monitor);

    expect(Alert::query()->sole()->message)->toBe('retired tempC is 1260, usually 1200 ±10 at this hour');
});

it('checks every monitor every five minutes, and relearns them all nightly', function (): void {
    $detect = collect(resolve(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'detect-anomalies');
    $learn = collect(resolve(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'learn-anomaly-baselines');

    expect($detect)->toBeInstanceOf(CallbackEvent::class)
        ->and($detect->expression)->toBe('*/5 * * * *')
        ->and($learn->expression)->toBe('30 2 * * *');

    $monitor = watchedStore();
    storeReadings($monitor->twin, CarbonImmutable::now()->subHours(12), fn (): float => 0.0, 5);
    $detect->run(app());
    expect(Alert::query()->count())->toBe(1);

    $learn->run(app());
    expect($monitor->refresh()->baseline['days'])->toEqual(0.5)
        ->and(resolve(DetectAnomalies::class))->toBeInstanceOf(DetectAnomalies::class)
        ->and(resolve(LearnAnomalyBaselines::class))->toBeInstanceOf(LearnAnomalyBaselines::class);
});

it("keeps anomaly alerts open when the gateway reports its own, and doesn't let the gateway raise them", function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $token = resolve(IssueGatewayToken::class)->handle($twin);
    $anomaly = Alert::factory()->for($twin)->create(['rule' => 'anomaly:tempC', 'asset' => 'store']);

    $this->withToken($token)->postJson(route('gateway.alerts.store'), ['open' => []])->assertExactJson(['raised' => 0, 'cleared' => 0]);
    expect($anomaly->refresh()->cleared_at)->toBeNull();

    $this->withToken($token)
        ->postJson(route('gateway.alerts.store'), ['open' => [['rule' => 'anomaly:x', 'asset' => 'store', 'severity' => 'info', 'message' => 'm', 'value' => 1]]])
        ->assertJsonValidationErrors('open.0.rule');
});

it('lets editors watch a metric, learning from its history at once, and change and stop it', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $twin = twinIn($organisation);
    storeReadings($twin, CarbonImmutable::now()->subDays(20), fn (): float => -19.0);

    $this->actingAs($user)->post(route('twins.anomaly-monitors.store', $twin), [
        'asset' => 'store', 'metric' => 'tempC', 'sensitivity' => 'high', 'severity' => 'critical', 'bands' => true, 'flatline_hours' => '',
    ])->assertRedirect();

    $monitor = AnomalyMonitor::query()->sole();
    expect($monitor)->sensitivity->toBe(AnomalySensitivity::High)->severity->toBe(AlertSeverity::Critical)
        ->flatline_hours->toBeNull()->created_by->toBe($user->id)
        ->and($monitor->ready())->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::AnomalyMonitorSaved)->sole())
        ->subject->toBe('store.tempC')->twin_id->toBe($twin->id)
        ->details->toBe(['sensitivity' => 'high', 'flatlineHours' => null, 'severity' => 'critical']);

    $this->get(route('twins.show', $twin))->assertInertia(fn ($page) => $page
        ->where('anomalies.learningDays', 14)
        ->where('anomalies.monitors.0', [
            'id' => $monitor->id, 'asset' => 'store', 'metric' => 'tempC', 'sensitivity' => 'high', 'severity' => 'critical',
            'bands' => true, 'flatlineHours' => null, 'learnedDays' => 20, 'ready' => true, 'open' => [],
        ]));

    // Turning the band off clears what it raised; the flatline check stays.
    Alert::factory()->for($twin)->create(['rule' => 'anomaly:tempC', 'asset' => 'store', 'message' => 'Unusual']);
    $this->get(route('twins.show', $twin))->assertInertia(fn ($page) => $page->where('anomalies.monitors.0.open', ['Unusual']));
    $this->put(route('twins.anomaly-monitors.update', [$twin, $monitor]), [
        'sensitivity' => 'low', 'severity' => 'info', 'bands' => false, 'flatline_hours' => 6,
    ])->assertRedirect();

    expect($monitor->refresh())->bands->toBeFalse()->flatline_hours->toBe(6)->severity->toBe(AlertSeverity::Info)
        ->and(Alert::query()->sole()->cleared_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::AnomalyMonitorSaved)->latest('id')->first()?->details)
        ->toBe(['sensitivity' => 'off', 'flatlineHours' => 6, 'severity' => 'info']);

    Alert::factory()->for($twin)->create(['rule' => 'anomaly:tempC:flat', 'asset' => 'store']);
    $this->delete(route('twins.anomaly-monitors.destroy', [$twin, $monitor]))->assertRedirect();

    expect(AnomalyMonitor::query()->count())->toBe(0)
        ->and($twin->openAlerts()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::AnomalyMonitorRemoved)->sole()->subject)->toBe('store.tempC');
});

it('checks what a monitor is asked for', function (array $input, string $error): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.anomaly-monitors.store', $twin), [
        'asset' => 'store', 'metric' => 'tempC', 'sensitivity' => 'medium', 'severity' => 'warning', 'bands' => true, ...$input,
    ])->assertSessionHasErrors($error);
})->with([
    'an asset it does not have' => [['asset' => 'nowhere'], 'asset'],
    'a metric name' => [['metric' => 'Temp C'], 'metric'],
    'a sensitivity' => [['sensitivity' => 'extreme'], 'sensitivity'],
    'no checks' => [['bands' => false, 'flatline_hours' => null], 'bands'],
    'too long unchanged' => [['flatline_hours' => 500], 'flatline_hours'],
]);

it('lets only editors change what is watched or left out, on their own twin', function (): void {
    [$operator, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);
    $monitor = AnomalyMonitor::factory()->for($twin)->create();
    $exclusion = AnomalyExclusion::factory()->for($twin)->create();
    $settings = ['sensitivity' => 'medium', 'severity' => 'warning', 'bands' => true];

    $this->actingAs($operator)->post(route('twins.anomaly-monitors.store', $twin), ['asset' => 'store', 'metric' => 'kW', ...$settings])->assertForbidden();
    $this->put(route('twins.anomaly-monitors.update', [$twin, $monitor]), $settings)->assertForbidden();
    $this->delete(route('twins.anomaly-monitors.destroy', [$twin, $monitor]))->assertForbidden();
    $this->post(route('twins.anomaly-exclusions.store', $twin), ['starts_at' => '2026-11-01T00:00:00Z', 'ends_at' => '2026-11-02T00:00:00Z'])->assertForbidden();
    $this->delete(route('twins.anomaly-exclusions.destroy', [$twin, $exclusion]))->assertForbidden();

    [$owner, $other] = member();
    $theirs = twinIn($other);
    $this->actingAs($owner)->delete(route('twins.anomaly-monitors.destroy', [$theirs, $monitor]))->assertNotFound();
    $this->delete(route('twins.anomaly-exclusions.destroy', [$theirs, $exclusion]))->assertNotFound();

    $this->actingAs($operator)->get(route('twins.show', $twin))->assertInertia(fn ($page) => $page
        ->where('anomalies.monitors.0.ready', false)
        ->where('anomalies.monitors.0.learnedDays', 0)
        ->where('anomalies.exclusions.0.note', 'Shutdown'));
});

it('lets editors leave a period out, relearning without it, and count it again', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    storeReadings($twin, CarbonImmutable::now()->subDays(20), fn (CarbonImmutable $at): float => $at->greaterThan(now()->subDays(10)) ? 5.0 : -19.0);
    $monitor = AnomalyMonitor::factory()->for($twin)->create();

    $this->actingAs($user)->post(route('twins.anomaly-exclusions.store', $twin), [
        'starts_at' => now()->subDays(10)->toIso8601String(), 'ends_at' => now()->toIso8601String(), 'note' => '  Defrost  ',
    ])->assertRedirect();

    $exclusion = AnomalyExclusion::query()->sole();
    expect($exclusion)->note->toBe('Defrost')->created_by->toBe($user->id)
        ->and($exclusion->starts_at->equalTo(now()->subDays(10)))->toBeTrue()
        ->and($monitor->refresh()->baseline['slots']['wd-15']['median'])->toEqual(-19.0)
        ->and(AuditEvent::query()->where('action', AuditAction::AnomalyExclusionAdded)->sole())
        ->subject->toBe('Defrost')->details->toBe(['from' => now()->subDays(10)->toIso8601String(), 'to' => now()->toIso8601String()]);

    $this->get(route('twins.show', $twin))->assertInertia(fn ($page) => $page->where('anomalies.exclusions.0', [
        'id' => $exclusion->id, 'startsAt' => now()->subDays(10)->toIso8601String(), 'endsAt' => now()->toIso8601String(), 'note' => 'Defrost',
    ]));

    $this->delete(route('twins.anomaly-exclusions.destroy', [$twin, $exclusion]))->assertRedirect();
    expect(AnomalyExclusion::query()->count())->toBe(0)
        ->and($monitor->refresh()->baseline['slots']['wd-15']['median'])->not->toBe(-19.0)
        ->and(AuditEvent::query()->where('action', AuditAction::AnomalyExclusionRemoved)->sole()->subject)->toBe('Defrost');

    $this->post(route('twins.anomaly-exclusions.store', $twin), ['starts_at' => '2026-11-02T00:00:00Z', 'ends_at' => '2026-11-01T00:00:00Z'])->assertSessionHasErrors('ends_at');
    $this->post(route('twins.anomaly-exclusions.store', $twin), ['starts_at' => '2026-11-01T00:00:00Z', 'ends_at' => '2026-11-02T00:00:00Z', 'note' => ' '])->assertRedirect();
    expect(AnomalyExclusion::query()->sole()->note)->toBeNull();
});

it('gives charts the usual range of a watched metric, to members and through share links', function (): void {
    $monitor = watchedStore();
    $twin = $monitor->twin;
    [$viewer] = member(OrganisationRole::Viewer);
    $twin->organisation->memberships()->create(['user_id' => $viewer->id, 'role' => OrganisationRole::Viewer]);
    storeReadings($twin, CarbonImmutable::now()->subHours(2), fn (): float => -19.0, 30);
    $from = now()->subHours(2)->subMinute()->getTimestampMs();
    $query = ['asset' => 'store', 'metric' => 'tempC', 'from' => $from, 'to' => now()->getTimestampMs(), 'buckets' => 10];

    $this->actingAs($viewer)->getJson(route('twins.readings.index', [$twin, ...$query]))
        ->assertOk()
        ->assertJsonPath('expected.0.low', -20.5)
        ->assertJsonPath('expected.0.high', -17.5)
        ->assertJsonCount(4, 'expected')
        ->assertJsonCount(4, 'points');

    TwinShareLink::factory()->for($twin)->token('share-token-for-anomaly-bands-0123456789')->create();
    $this->getJson('/share/share-token-for-anomaly-bands-0123456789/readings?'.http_build_query($query))->assertJsonCount(4, 'expected');

    $this->getJson(route('twins.readings.index', [$twin, ...$query, 'metric' => 'kW']))->assertJsonPath('expected', null);
    $monitor->forceFill(['baseline' => ['days' => 21.0, 'slots' => []]])->save();
    $this->getJson(route('twins.readings.index', [$twin, ...$query]))->assertJsonPath('expected', []);
});
