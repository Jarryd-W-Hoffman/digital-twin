<?php

declare(strict_types=1);

use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUpload;
use App\Jobs\WatchPhotoProcessing;
use App\Models\ProjectAsset;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->freezeSecond();
    Queue::fake();
    Storage::fake('local');
    config(['meridian.photos.nodeodm_url' => 'http://nodeodm:3000', 'meridian.photos.poll_seconds' => 30, 'meridian.photos.timeout_hours' => 48]);
    $this->asset = ProjectAsset::factory()->mesh()->processing()->create([
        'original_name' => 'photos.zip',
        'options' => ['photos' => true, 'quality' => 'fast', 'dtm' => true, 'nodeodm_task' => 'task-1'],
        'progress' => 5,
        'log' => 'Sent 77 photos to NodeODM to process (fast quality)',
    ]);
    Storage::disk('local')->put($this->asset->sourceKey(), 'photos');
});

/**
 * NodeODM with one task, at a status and progress; the responses given
 * take the place of its usual ones.
 *
 * @param  array<string, mixed>  $instead
 */
function nodeOdmTask(int $status, float $progress = 0, array $instead = []): void
{
    Http::fake($instead + [
        'nodeodm:3000/task/task-1/info' => Http::response(['status' => ['code' => $status], 'progress' => $progress, 'imagesCount' => 77, 'processingTime' => 2_520_000]),
        'nodeodm:3000/task/task-1/output' => Http::response(['[INFO] Running', '[ERROR] Not enough overlap', 'Child returned 1']),
        'nodeodm:3000/task/task-1/download/all.zip' => Http::response('PK all.zip'),
        'nodeodm:3000/task/remove' => Http::response(['success' => true]),
    ]);
}

it('runs on the conversions queue, once, and lets go of a deleted upload', function (): void {
    $job = new WatchPhotoProcessing($this->asset, CarbonImmutable::now());

    expect($job->connection)->toBe('conversions')
        ->and($job->queue)->toBe('conversions')
        ->and($job->deleteWhenMissingModels ?? true)->toBeTrue();
});

it('shows how far along NodeODM is, and asks again later', function (float $progress, int $shown): void {
    nodeOdmTask(20, $progress);

    app()->call([new WatchPhotoProcessing($this->asset, CarbonImmutable::now()->subHour(), 3), 'handle']);

    expect($this->asset->refresh()->progress)->toBe($shown)
        ->and($this->asset->status)->toBe(ProjectAssetStatus::Processing);
    Queue::assertPushed(WatchPhotoProcessing::class, fn (WatchPhotoProcessing $job): bool => $job->delay === 30 && $job->outOfTouch === 0 && $job->since->equalTo(CarbonImmutable::now()->subHour()));
})->with([
    'starting' => [0, 5],
    'half way' => [50.4, 37],
    'nearly' => [100, 70],
]);

it("leaves the progress alone while it hasn't moved", function (): void {
    nodeOdmTask(10);
    $this->asset->update(['progress' => 5]);
    $updated = $this->asset->refresh()->updated_at;
    $this->travel(1)->minute();

    app()->call([new WatchPhotoProcessing($this->asset, CarbonImmutable::now()), 'handle']);

    expect($this->asset->refresh()->updated_at->equalTo($updated))->toBeTrue();
});

it('fetches the outputs when done, as an OpenDroneMap export to convert', function (): void {
    nodeOdmTask(40, 100);

    app()->call([new WatchPhotoProcessing($this->asset, CarbonImmutable::now()->subHours(3)), 'handle']);

    expect(Storage::disk('local')->get($this->asset->sourceKey()))->toBe('PK all.zip')
        ->and($this->asset->refresh()->options)->toBe(['opendronemap' => true])
        ->and($this->asset->status)->toBe(ProjectAssetStatus::Processing)
        ->and($this->asset->progress)->toBe(70)
        ->and($this->asset->log)->toBe("Sent 77 photos to NodeODM to process (fast quality)\nProcessed by NodeODM in 42 minutes")
        ->and(is_dir(storage_path('app/conversions/'.$this->asset->id)))->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/task/remove'));
    Queue::assertPushed(ConvertUpload::class, fn (ConvertUpload $job): bool => $job->asset->is($this->asset));
});

it('fails with what NodeODM said, keeping the photos for a retry', function (int $status, ?array $output, string $error): void {
    nodeOdmTask($status, instead: ['nodeodm:3000/task/task-1/output' => $output === null ? Http::response('', 500) : Http::response($output)]);

    app()->call([new WatchPhotoProcessing($this->asset, CarbonImmutable::now()), 'handle']);

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Failed)
        ->and($this->asset->error)->toBe($error)
        ->and($this->asset->options)->toBe(['photos' => true, 'quality' => 'fast', 'dtm' => true])
        ->and(Storage::disk('local')->get($this->asset->sourceKey()))->toBe('photos');
    Queue::assertNothingPushed();
})->with([
    'failed' => [30, ['[ERROR] Not enough overlap', 'Child returned 1'], "NodeODM couldn't process the photos:\n[ERROR] Not enough overlap\nChild returned 1"],
    'cancelled, saying nothing' => [50, null, "NodeODM couldn't process the photos:\n(it said nothing more)"],
]);

it("fails when the outputs can't be fetched", function (Closure $download, string $error): void {
    nodeOdmTask(40, 100, ['nodeodm:3000/task/task-1/download/all.zip' => $download]);

    app()->call([new WatchPhotoProcessing($this->asset, CarbonImmutable::now()), 'handle']);

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Failed)
        ->and($this->asset->error)->toBe($error)
        ->and(Storage::disk('local')->get($this->asset->sourceKey()))->toBe('photos');
})->with([
    'refused' => [fn () => Http::response(['error' => 'Gone'], 404), 'NodeODM refused the request: Gone'],
    'broken' => [fn (): Closure => fn (): never => throw new RuntimeException('disk'), "NodeODM's outputs couldn't be fetched."],
]);

it('keeps asking while NodeODM is out of touch, then gives up', function (int $outOfTouch, bool $givesUp): void {
    Http::fake(['nodeodm:3000/*' => fn (): never => throw new ConnectionException('refused')]);

    app()->call([new WatchPhotoProcessing($this->asset, CarbonImmutable::now(), $outOfTouch), 'handle']);

    if ($givesUp) {
        expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Failed)
            ->and($this->asset->error)->toBe("NodeODM, the photo processor, can't be reached.");
        Queue::assertNothingPushed();
    } else {
        Queue::assertPushed(WatchPhotoProcessing::class, fn (WatchPhotoProcessing $job): bool => $job->outOfTouch === $outOfTouch + 1);
    }
})->with([
    'once' => [0, false],
    'the tenth time' => [9, true],
]);

it('stops processing that takes too long', function (): void {
    nodeOdmTask(20, 50);

    app()->call([new WatchPhotoProcessing($this->asset, CarbonImmutable::now()->subHours(49)), 'handle']);

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Failed)
        ->and($this->asset->error)->toBe('NodeODM took longer than 48 hours, so it was stopped.');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/task/remove'));
});

it('lets go of an upload no longer waiting on NodeODM', function (array $state): void {
    $this->asset->update($state);
    nodeOdmTask(20, 50);

    app()->call([new WatchPhotoProcessing($this->asset, CarbonImmutable::now()), 'handle']);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    'failed' => [['status' => ProjectAssetStatus::Failed]],
    'no task' => [['options' => ['photos' => true]]],
]);

it('fails the upload when the worker gives up on following it', function (): void {
    nodeOdmTask(40, 100);

    new WatchPhotoProcessing($this->asset, CarbonImmutable::now())->failed(new RuntimeException('Allowed memory size exhausted'));

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Failed)
        ->and($this->asset->error)->toBe('Meridian stopped following NodeODM unexpectedly. Retry it to process the photos again.')
        ->and($this->asset->options)->toBe(['photos' => true, 'quality' => 'fast', 'dtm' => true]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/task/remove'));
});

it("leaves an upload alone when the worker gives up after it's no longer waiting on NodeODM", function (): void {
    $this->asset->update(['options' => ['opendronemap' => true]]);

    new WatchPhotoProcessing($this->asset, CarbonImmutable::now())->failed(null);

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Processing);
});
