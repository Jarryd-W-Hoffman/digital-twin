<?php

declare(strict_types=1);

use App\Actions\RecordReadings;
use App\Models\Reading;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Support\Facades\Event;

it('belongs to its twin, and prunes readings past their retention', function (): void {
    config(['meridian.readings.retention_days' => 30]);
    [$twin] = gatewayTwin();
    resolve(RecordReadings::class)->handle($twin, [
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 1.0, 'at' => now()->subDays(31)->getTimestampMs()],
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 2.0, 'at' => now()->subDays(29)->getTimestampMs()],
    ]);
    Event::fake([ModelsPruned::class]);

    $this->artisan('model:prune', ['--model' => [Reading::class]])->assertSuccessful();
    $this->artisan('model:prune', ['--model' => [Reading::class]])->assertSuccessful();

    Event::assertDispatchedTimes(ModelsPruned::class, 1);

    $left = Reading::query()->sole();
    expect($left->value)->toBe(2.0)
        ->and($left->twin->is($twin))->toBeTrue();
});

it('goes when its twin does', function (): void {
    $twin = Reading::factory()->create()->twin;

    $twin->delete();

    expect(Reading::query()->count())->toBe(0);
});
