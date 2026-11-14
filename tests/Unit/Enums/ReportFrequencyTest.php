<?php

declare(strict_types=1);

use App\Enums\ReportFrequency;
use Carbon\CarbonImmutable;

it('falls due at 7:00 each day, in the given time zone', function (string $at, string $due): void {
    expect(ReportFrequency::Daily->dueAt(CarbonImmutable::parse($at), 'Pacific/Auckland')->toIso8601String())->toBe($due);
})->with([
    'just after 7:00 there' => ['2026-11-13T18:00:00Z', '2026-11-13T18:00:00+00:00'],
    'later that day' => ['2026-11-14T05:00:00Z', '2026-11-13T18:00:00+00:00'],
    'before 7:00 there' => ['2026-11-13T17:59:59Z', '2026-11-12T18:00:00+00:00'],
]);

it('falls due at 7:00 on Mondays', function (string $at, string $due): void {
    expect(ReportFrequency::Weekly->dueAt(CarbonImmutable::parse($at), 'UTC')->toIso8601String())->toBe($due);
})->with([
    'that Monday' => ['2026-11-16T07:00:00Z', '2026-11-16T07:00:00+00:00'],
    'later that week' => ['2026-11-21T23:00:00Z', '2026-11-16T07:00:00+00:00'],
    'early on a Monday' => ['2026-11-16T06:00:00Z', '2026-11-09T07:00:00+00:00'],
    'a Sunday' => ['2026-11-15T12:00:00Z', '2026-11-09T07:00:00+00:00'],
]);

it('covers the day or week before', function (): void {
    $due = CarbonImmutable::parse('2026-11-16T07:00:00Z');

    expect(ReportFrequency::Daily->since($due)->toIso8601String())->toBe('2026-11-15T07:00:00+00:00')
        ->and(ReportFrequency::Weekly->since($due)->toIso8601String())->toBe('2026-11-09T07:00:00+00:00')
        ->and(ReportFrequency::Daily->label())->toBe('Daily')
        ->and(ReportFrequency::Weekly->label())->toBe('Weekly');
});
