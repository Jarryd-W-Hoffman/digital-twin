<?php

declare(strict_types=1);

use App\Actions\RecordReadings;
use App\Enums\OrganisationRole;

it("serves a twin's history to anyone who can view it", function (): void {
    [$viewer, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $at = now()->getTimestampMs();
    resolve(RecordReadings::class)->handle($twin, [['asset' => 'store', 'metric' => 'tempC', 'value' => -18.0, 'at' => $at - 5000]]);

    $this->actingAs($viewer)->getJson(route('twins.readings.index', [$twin, 'asset' => 'store', 'metric' => 'tempC', 'from' => $at - 60_000, 'to' => $at, 'buckets' => 60]))
        ->assertOk()
        ->assertJsonPath('bucketMs', 1000)
        ->assertJsonPath('points.0.mean', -18)
        ->assertJsonPath('points.0.count', 1);
});

it('checks what it is asked for', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->getJson(route('twins.readings.index', [$twin, 'metric' => 'Bad', 'from' => 10, 'to' => 5, 'buckets' => 5]))
        ->assertJsonValidationErrors(['asset', 'metric', 'to', 'buckets']);
});

it('keeps other organisations out', function (): void {
    [$user] = member();
    [, $other] = member();
    $twin = twinIn($other);

    $this->actingAs($user)->getJson(route('twins.readings.index', [$twin, 'asset' => 'store', 'metric' => 'tempC', 'from' => 0, 'to' => 1000]))
        ->assertForbidden();
});
