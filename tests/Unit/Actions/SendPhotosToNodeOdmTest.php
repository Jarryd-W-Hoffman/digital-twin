<?php

declare(strict_types=1);

use App\Actions\SendPhotosToNodeOdm;
use App\Enums\Feature;
use App\Exceptions\ConversionFailed;
use App\Jobs\WatchPhotoProcessing;
use App\Models\ProjectAsset;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    config(['meridian.photos.nodeodm_url' => 'http://nodeodm:3000', 'meridian.photos.poll_seconds' => 30]);
    $this->asset = ProjectAsset::factory()->mesh()->processing()->create(['name' => 'Aukerman Park', 'original_name' => 'photos.zip', 'options' => ['photos' => true], 'log' => null, 'progress' => 0]);
    $this->asset->project->organisation->update(['features' => [Feature::PhotoProcessing]]);
});

/**
 * NodeODM taking a task's photos; the responses given take the place of its
 * usual ones.
 *
 * @param  array<string, mixed>  $instead
 */
function nodeOdmTakesPhotos(array $instead = []): void
{
    Http::fake($instead + [
        'nodeodm:3000/task/new/init' => Http::response(['uuid' => 'task-1']),
        'nodeodm:3000/task/new/upload/task-1' => Http::response(['success' => true]),
        'nodeodm:3000/task/new/commit/task-1' => Http::response(['uuid' => 'task-1']),
        'nodeodm:3000/task/remove' => Http::response(['success' => true]),
    ]);
}

/**
 * The photos NodeODM was sent, in the order sent, batch by batch.
 *
 * @return list<list<string>>
 */
function sentPhotos(): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/task/new/upload/'))
        ->map(fn (array $pair): array => collect($pair[0]->data())->pluck('filename')->all())
        ->values()
        ->all();
}

/**
 * The options the task was opened with.
 *
 * @return list<array{name: string, value: string|bool}>
 */
function taskOptions(): array
{
    $init = Http::recorded()->first(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/task/new/init'));
    /** @var list<array{name: string, value: string|bool}> $options */
    $options = json_decode((string) collect($init[0]->data())->firstWhere('name', 'options')['contents'], true);

    return $options;
}

it('sends the photos to NodeODM in batches, starts it, and follows it', function (): void {
    nodeOdmTakesPhotos();
    Storage::disk('local')->put($this->asset->sourceKey(), dronePhotos(23, [
        'flight/geo.txt' => 'EPSG:4326',
        'flight/notes.docx' => 'not a photo',
        '__MACOSX/flight/._DSC00001.JPG' => 'resource fork',
        'flight/.hidden.jpg' => 'hidden',
    ]));

    resolve(SendPhotosToNodeOdm::class)->handle($this->asset);

    $sent = sentPhotos();

    expect(array_map(count(...), $sent))->toBe([10, 10, 4])
        ->and($sent[0][0])->toBe('geo.txt')
        ->and(array_merge(...$sent))->not->toContain('notes.docx')->not->toContain('._DSC00001.JPG')->not->toContain('.hidden.jpg')
        ->and(taskOptions())->toBe([['name' => 'dsm', 'value' => true], ['name' => 'dtm', 'value' => true], ['name' => 'auto-boundary', 'value' => true]])
        ->and($this->asset->refresh()->options)->toBe(['photos' => true, 'nodeodm_task' => 'task-1'])
        ->and($this->asset->progress)->toBe(5)
        ->and($this->asset->log)->toBe('Sent 23 photos to NodeODM to process (standard quality)')
        ->and(is_dir(storage_path('app/conversions/'.$this->asset->id)))->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/task/new/commit/task-1'));
    Queue::assertPushed(WatchPhotoProcessing::class, fn (WatchPhotoProcessing $job): bool => $job->asset->is($this->asset) && $job->delay === 30 && $job->outOfTouch === 0);
});

it('asks for the quality and the models chosen', function (string $quality, bool $dtm, array $options): void {
    nodeOdmTakesPhotos();
    $this->asset->update(['options' => ['photos' => true, 'quality' => $quality, 'dtm' => $dtm]]);
    Storage::disk('local')->put($this->asset->sourceKey(), dronePhotos(5));

    resolve(SendPhotosToNodeOdm::class)->handle($this->asset);

    expect(taskOptions())->toBe($options);
})->with([
    'fast, no DTM' => ['fast', false, [['name' => 'dsm', 'value' => true], ['name' => 'dtm', 'value' => false], ['name' => 'auto-boundary', 'value' => true], ['name' => 'feature-quality', 'value' => 'medium'], ['name' => 'pc-quality', 'value' => 'low']]],
    'high' => ['high', true, [['name' => 'dsm', 'value' => true], ['name' => 'dtm', 'value' => true], ['name' => 'auto-boundary', 'value' => true], ['name' => 'feature-quality', 'value' => 'ultra'], ['name' => 'pc-quality', 'value' => 'high']]],
]);

it('refuses too few photos, or what is not a zip, without a task', function (string $bytes, string $message): void {
    Storage::disk('local')->put($this->asset->sourceKey(), $bytes);

    expect(fn () => resolve(SendPhotosToNodeOdm::class)->handle($this->asset))->toThrow(ConversionFailed::class, $message);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    'one photo' => [fn (): string => dronePhotos(1, ['flight/geo.txt' => 'EPSG:4326']), 'The zip has 1 photo. Processing needs at least 5, taken with plenty of overlap.'],
    'four photos' => [fn (): string => dronePhotos(4), 'The zip has 4 photos. Processing needs at least 5, taken with plenty of overlap.'],
    'not a zip' => ['not a zip', "The zip file can't be read."],
]);

it('deletes the task when sending the photos fails', function (): void {
    nodeOdmTakesPhotos(['nodeodm:3000/task/new/upload/task-1' => Http::response(['error' => 'Disk full'], 500)]);
    Storage::disk('local')->put($this->asset->sourceKey(), dronePhotos(5));

    expect(fn () => resolve(SendPhotosToNodeOdm::class)->handle($this->asset))->toThrow(ConversionFailed::class, 'NodeODM refused the request: Disk full');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/task/remove') && $request->data() === ['uuid' => 'task-1']);
    expect($this->asset->refresh()->options)->toBe(['photos' => true]);
});

it('refuses photos once the organisation has photo processing off', function (): void {
    $this->asset->project->organisation->update(['features' => []]);

    expect(fn () => resolve(SendPhotosToNodeOdm::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'Photo processing is off for this organisation, or this server no longer offers it.');
});
