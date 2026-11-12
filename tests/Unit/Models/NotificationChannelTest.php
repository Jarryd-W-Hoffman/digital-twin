<?php

declare(strict_types=1);

use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use App\Enums\AlertSeverity;
use App\Enums\NotificationChannelKind;
use App\Models\NotificationChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('takes alerts of at least its severity, and their clearing if it wants', function (): void {
    $channel = NotificationChannel::factory()->make(['min_severity' => AlertSeverity::Warning, 'notify_cleared' => false]);
    $noon = CarbonImmutable::parse('2026-11-12 12:00', 'UTC');

    expect($channel->decide(AlertSeverity::Info, AlertEvent::Raised, $noon))->toBeNull()
        ->and($channel->decide(AlertSeverity::Warning, AlertEvent::Raised, $noon))->toBe(AlertNotificationStatus::Queued)
        ->and($channel->decide(AlertSeverity::Critical, AlertEvent::Raised, $noon))->toBe(AlertNotificationStatus::Queued)
        ->and($channel->decide(AlertSeverity::Critical, AlertEvent::Cleared, $noon))->toBeNull();
});

it('takes escalations, not raises, when it escalates', function (): void {
    $noon = CarbonImmutable::parse('2026-11-12 12:00', 'UTC');
    $first = NotificationChannel::factory()->make();
    $later = NotificationChannel::factory()->escalation(15)->make();

    expect($first->escalates())->toBeFalse()
        ->and($first->decide(AlertSeverity::Warning, AlertEvent::Escalated, $noon))->toBeNull()
        ->and($later->escalates())->toBeTrue()
        ->and($later->decide(AlertSeverity::Warning, AlertEvent::Raised, $noon))->toBeNull()
        ->and($later->decide(AlertSeverity::Warning, AlertEvent::Escalated, $noon))->toBe(AlertNotificationStatus::Queued)
        ->and($later->decide(AlertSeverity::Warning, AlertEvent::Cleared, $noon))->toBe(AlertNotificationStatus::Queued);
});

it('keeps quiet overnight in its own time zone, except for critical alerts', function (): void {
    $channel = NotificationChannel::factory()->quiet('22:00', '07:00', 'Pacific/Auckland')->make(['min_severity' => AlertSeverity::Info]);
    // 23:30 in Auckland (UTC+13 in November), then 07:00 there, then midday.
    $night = CarbonImmutable::parse('2026-11-12 10:30', 'UTC');
    $morning = CarbonImmutable::parse('2026-11-12 18:00', 'UTC');
    $day = CarbonImmutable::parse('2026-11-12 23:00', 'UTC');

    expect($channel->isQuietAt($night))->toBeTrue()
        ->and($channel->isQuietAt($morning))->toBeFalse()
        ->and($channel->isQuietAt($day))->toBeFalse()
        ->and($channel->decide(AlertSeverity::Warning, AlertEvent::Raised, $night))->toBe(AlertNotificationStatus::Quiet)
        ->and($channel->decide(AlertSeverity::Warning, AlertEvent::Cleared, $night))->toBe(AlertNotificationStatus::Quiet)
        ->and($channel->decide(AlertSeverity::Critical, AlertEvent::Raised, $night))->toBe(AlertNotificationStatus::Queued);
});

it('keeps quiet within the same day too, and never without hours', function (): void {
    $lunch = NotificationChannel::factory()->quiet('12:00', '13:00')->make();

    expect($lunch->isQuietAt(CarbonImmutable::parse('2026-11-12 12:30', 'UTC')))->toBeTrue()
        ->and($lunch->isQuietAt(CarbonImmutable::parse('2026-11-12 13:00', 'UTC')))->toBeFalse()
        ->and($lunch->isQuietAt(CarbonImmutable::parse('2026-11-12 11:59', 'UTC')))->toBeFalse()
        ->and(NotificationChannel::factory()->make()->isQuietAt(now()))->toBeFalse();
});

it("lists its addresses, and shows only a webhook's host", function (): void {
    $email = NotificationChannel::factory()->make(['target' => " ops@example.com,oncall@example.com;\nboss@example.com "]);
    $slack = NotificationChannel::factory()->webhook(NotificationChannelKind::Slack, 'https://hooks.slack.com/services/T0/B0/secret')->make();

    expect($email->addresses())->toBe(['ops@example.com', 'oncall@example.com', 'boss@example.com'])
        ->and($email->displayTarget())->toBe('ops@example.com, oncall@example.com, boss@example.com')
        ->and($slack->displayTarget())->toBe('hooks.slack.com/…');
});

it('keeps its target and secret encrypted', function (): void {
    $channel = NotificationChannel::factory()->webhook()->create();

    $row = DB::table('notification_channels')->where('id', $channel->id)->sole();

    expect($row->target)->not->toContain('hooks.example.com')
        ->and($row->secret)->not->toBe('whsec_test')
        ->and($channel->refresh()->secret)->toBe('whsec_test');
});

it('ranks severities', function (): void {
    expect(AlertSeverity::Critical->atLeast(AlertSeverity::Warning))->toBeTrue()
        ->and(AlertSeverity::Info->atLeast(AlertSeverity::Warning))->toBeFalse()
        ->and(AlertSeverity::Info->atLeast(AlertSeverity::Info))->toBeTrue();
});
