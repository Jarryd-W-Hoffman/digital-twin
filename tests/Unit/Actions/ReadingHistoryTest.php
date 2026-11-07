<?php

declare(strict_types=1);

use App\Actions\ReadingHistory;
use App\Actions\RecordReadings;
use App\Models\Reading;

it('averages a metric into time buckets with lows and highs', function (): void {
    [$twin] = gatewayTwin();
    $start = 1_793_000_000_000;
    resolve(RecordReadings::class)->handle($twin, [
        ['asset' => 'store', 'metric' => 'tempC', 'value' => -20.0, 'at' => $start],
        ['asset' => 'store', 'metric' => 'tempC', 'value' => -18.0, 'at' => $start + 30_000],
        ['asset' => 'store', 'metric' => 'tempC', 'value' => -10.0, 'at' => $start + 60_000],
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 99.0, 'at' => $start + 120_000],
        ['asset' => 'store', 'metric' => 'setpointC', 'value' => 5.0, 'at' => $start],
        ['asset' => 'plant', 'metric' => 'tempC', 'value' => 5.0, 'at' => $start],
    ]);
    [$other] = gatewayTwin();
    resolve(RecordReadings::class)->handle($other, [['asset' => 'store', 'metric' => 'tempC', 'value' => 50.0, 'at' => $start]]);

    $history = resolve(ReadingHistory::class)->handle($twin, 'store', 'tempC', $start, $start + 120_000, buckets: 2);

    expect($history)->toBe([
        'bucketMs' => 60_000,
        'points' => [
            ['t' => $start - $start % 60_000, 'mean' => -19.0, 'min' => -20.0, 'max' => -18.0, 'count' => 2],
            ['t' => $start - $start % 60_000 + 60_000, 'mean' => -10.0, 'min' => -10.0, 'max' => -10.0, 'count' => 1],
        ],
    ]);
});

it('never buckets finer than a second', function (): void {
    [$twin] = gatewayTwin();

    expect(resolve(ReadingHistory::class)->handle($twin, 'store', 'tempC', 0, 10_000)['bucketMs'])->toBe(1000)
        ->and(resolve(ReadingHistory::class)->handle($twin, 'store', 'tempC', 0, 10_000)['points'])->toBe([]);
});

it('stores big batches in chunks', function (): void {
    [$twin] = gatewayTwin();
    $readings = array_map(fn (int $i): array => ['asset' => 'store', 'metric' => 'tempC', 'value' => (float) $i, 'at' => 1_793_000_000_000 + $i], range(1, 1200));

    expect(resolve(RecordReadings::class)->handle($twin, $readings))->toBe(1200)
        ->and(Reading::query()->count())->toBe(1200);
});
