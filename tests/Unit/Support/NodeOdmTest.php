<?php

declare(strict_types=1);

use App\Exceptions\ConversionFailed;
use App\Support\NodeOdm;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['meridian.photos.nodeodm_url' => 'http://nodeodm:3000/', 'meridian.photos.nodeodm_token' => null]);
});

it('opens a task with its options, which waits for photos', function (): void {
    Http::fake(['nodeodm:3000/task/new/init' => Http::response(['uuid' => 'task-1'])]);

    expect(resolve(NodeOdm::class)->open('Aukerman Park', ['dsm' => true, 'pc-quality' => 'low']))->toBe('task-1');

    Http::assertSent(function (Request $request): bool {
        $fields = collect($request->data())->pluck('contents', 'name');

        return $request->url() === 'http://nodeodm:3000/task/new/init'
            && $request->isMultipart()
            && $fields['name'] === 'Aukerman Park'
            && $fields['skipPostProcessing'] === 'true'
            && $fields['options'] === '[{"name":"dsm","value":true},{"name":"pc-quality","value":"low"}]';
    });
});

it('sends photos in a batch, starts the task, and asks after it', function (): void {
    Http::fake([
        'nodeodm:3000/task/new/upload/task-1' => Http::response(['success' => true]),
        'nodeodm:3000/task/new/commit/task-1' => Http::response(['uuid' => 'task-1']),
        'nodeodm:3000/task/task-1/info' => Http::response(['uuid' => 'task-1', 'status' => ['code' => 20], 'progress' => 45.75, 'imagesCount' => 77, 'processingTime' => 1_909_800]),
    ]);
    $photo = fopen('php://memory', 'r+');
    fwrite($photo, 'jpeg');
    rewind($photo);

    resolve(NodeOdm::class)->upload('task-1', ['DSC00001.JPG' => $photo]);
    resolve(NodeOdm::class)->commit('task-1');

    expect(resolve(NodeOdm::class)->info('task-1'))->toBe(['status' => NodeOdm::RUNNING, 'progress' => 45, 'images' => 77, 'minutes' => 32]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://nodeodm:3000/task/new/upload/task-1'
        && collect($request->data())->contains(fn (array $field): bool => $field['name'] === 'images' && $field['filename'] === 'DSC00001.JPG'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://nodeodm:3000/task/new/commit/task-1' && $request->method() === 'POST');
});

it("reads the last lines of a task's output", function (): void {
    Http::fake(['nodeodm:3000/task/task-1/output' => Http::response(['[INFO] one', '', '[INFO] two', '[ERROR] Not enough photos', '  ', '[INFO] done', 'Child returned 1', 'end'])]);

    expect(resolve(NodeOdm::class)->lastLines('task-1', 3))->toBe("[INFO] done\nChild returned 1\nend");
});

it('downloads the outputs without reading them, and deletes the task', function (): void {
    $zip = storage_path('framework/testing/all-'.uniqid().'.zip');
    Http::fake([
        'nodeodm:3000/task/task-1/download/all.zip' => Http::response('{"error": "only looks like one"}', 200, ['Content-Type' => 'application/zip']),
        'nodeodm:3000/task/remove' => Http::response(['success' => true]),
    ]);

    resolve(NodeOdm::class)->download('task-1', $zip);
    resolve(NodeOdm::class)->remove('task-1');

    expect(file_get_contents($zip))->toBe('{"error": "only looks like one"}');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://nodeodm:3000/task/remove' && $request->data() === ['uuid' => 'task-1']);
    File::delete($zip);
});

it('passes its token when it asks for one', function (): void {
    config(['meridian.photos.nodeodm_token' => 'secret']);
    Http::fake(['nodeodm:3000/task/task-1/info*' => Http::response(['status' => ['code' => 40], 'progress' => 100, 'imagesCount' => 5])]);

    resolve(NodeOdm::class)->info('task-1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://nodeodm:3000/task/task-1/info?token=secret');
});

it('says why NodeODM did not do what was asked', function (Closure $response, string $message): void {
    Http::fake(['nodeodm:3000/*' => $response]);

    expect(fn () => resolve(NodeOdm::class)->info('task-1'))->toThrow(ConversionFailed::class, $message);
})->with([
    'unreachable' => [fn (): Closure => fn (): never => throw new ConnectionException('refused'), "NodeODM, the photo processor, can't be reached."],
    'an error' => [fn () => Http::response(['error' => 'task-1 not found'], 404), 'NodeODM refused the request: task-1 not found'],
    'an error without a reason' => [fn () => Http::response('', 500), 'NodeODM refused the request: 500'],
    'an error it calls a success' => [fn () => Http::response(['error' => 'task-1 not found']), 'NodeODM refused the request: task-1 not found'],
]);
