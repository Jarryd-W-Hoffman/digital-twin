<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ConversionFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * NodeODM, OpenDroneMap's processing server (AGPL-3.0), run beside Meridian
 * as a separate service and reached over its REST API. A task is opened,
 * given its photos in batches, then committed; it runs on its own while
 * Meridian asks after it, and leaves its outputs in an all.zip.
 */
final readonly class NodeOdm
{
    public const int QUEUED = 10;

    public const int RUNNING = 20;

    public const int FAILED = 30;

    public const int COMPLETED = 40;

    public const int CANCELED = 50;

    /**
     * Opens a task, which waits for its photos until committed.
     *
     * @param  array<string, string|bool>  $options  OpenDroneMap's options by name.
     */
    public function open(string $name, array $options): string
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->asMultipart()->post('task/new/init', [
            ['name' => 'name', 'contents' => $name],
            // Meridian tiles the point cloud itself, so NodeODM needn't.
            ['name' => 'skipPostProcessing', 'contents' => 'true'],
            ['name' => 'options', 'contents' => (string) json_encode(array_map(
                fn (string $option, string|bool $value): array => ['name' => $option, 'value' => $value],
                array_keys($options),
                array_values($options),
            ))],
        ]));

        return $this->text($response, 'uuid');
    }

    /**
     * Sends the task a batch of photos.
     *
     * @param  array<string, resource>  $photos  Each photo's name, and its contents.
     */
    public function upload(string $task, array $photos): void
    {
        $this->send(function (PendingRequest $http) use ($task, $photos): Response {
            foreach ($photos as $name => $contents) {
                $http->attach('images', $contents, $name);
            }

            return $http->timeout(600)->post('task/new/upload/'.$task);
        });
    }

    /**
     * Starts the task, with the photos it has.
     */
    public function commit(string $task): void
    {
        $this->send(fn (PendingRequest $http): Response => $http->post('task/new/commit/'.$task));
    }

    /**
     * Where the task is: its status code, how far along it is (0 to 100,
     * which NodeODM gives with decimals), how many photos it has, and how
     * many minutes it has spent processing them.
     *
     * @return array{status: int, progress: int, images: int, minutes: int}
     */
    public function info(string $task): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->get('task/'.$task.'/info'));

        return [
            'status' => $this->number($response, 'status.code'),
            'progress' => $this->number($response, 'progress'),
            'images' => $this->number($response, 'imagesCount'),
            'minutes' => (int) round($this->number($response, 'processingTime') / 60_000),
        ];
    }

    /**
     * The task's last lines of console output, which say why it failed.
     */
    public function lastLines(string $task, int $lines = 5): string
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->get('task/'.$task.'/output'));
        /** @var list<string> $output */
        $output = array_values(array_filter(array_map(fn (mixed $line): string => is_string($line) ? mb_trim($line) : '', (array) $response->json()), fn (string $line): bool => $line !== ''));

        return implode("\n", array_slice($output, -$lines));
    }

    /**
     * Saves the task's outputs (all.zip) to a file.
     */
    public function download(string $task, string $path): void
    {
        $this->send(fn (PendingRequest $http): Response => $http->sink($path)->timeout(3600)->get('task/'.$task.'/download/all.zip'));
    }

    /**
     * Stops the task, if it's running, and deletes it and its files.
     */
    public function remove(string $task): void
    {
        $this->send(fn (PendingRequest $http): Response => $http->post('task/remove', ['uuid' => $task]));
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request): Response
    {
        $http = Http::baseUrl(mb_rtrim(config()->string('meridian.photos.nodeodm_url'), '/').'/')
            ->connectTimeout(10)
            ->timeout(60)
            ->acceptJson()
            ->withQueryParameters(array_filter(['token' => config('meridian.photos.nodeodm_token')]));

        try {
            $response = $request($http)->throw();
        } catch (ConnectionException) {
            throw new ConversionFailed(__("NodeODM, the photo processor, can't be reached."));
        } catch (RequestException $requestException) {
            throw new ConversionFailed(__('NodeODM refused the request: :error', ['error' => $this->text($requestException->response, 'error') ?: (string) $requestException->response->status()]), $requestException->getCode(), $requestException);
        }

        // Only a JSON answer can be an error: a download's body stays on disk, unread.
        if (str_contains($response->header('Content-Type'), 'json') && is_string($response->json('error'))) {
            throw new ConversionFailed(__('NodeODM refused the request: :error', ['error' => $this->text($response, 'error')]));
        }

        return $response;
    }

    private function text(Response $response, string $key): string
    {
        $value = $response->json($key);

        return is_string($value) ? $value : '';
    }

    private function number(Response $response, string $key): int
    {
        $value = $response->json($key);

        return is_numeric($value) ? (int) $value : 0;
    }
}
