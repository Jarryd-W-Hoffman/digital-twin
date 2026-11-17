<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use App\Support\Converter;
use App\Support\NodeOdm;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Follows drone photos while NodeODM processes them, asking after them every
 * MERIDIAN_PHOTOS_POLL_SECONDS rather than holding a converter worker:
 *
 *     queued or running: show its progress (5 to 70%), and ask again later
 *     done: fetch its all.zip as the upload's original, now an OpenDroneMap
 *         export, delete the task, and convert it (split, then the mesh)
 *     failed or cancelled: fail with its last lines, keeping the photos
 *     out of touch for 10 tries in a row, or past MERIDIAN_PHOTOS_TIMEOUT_HOURS:
 *         fail, and stop the task
 */
#[DeleteWhenMissingModels]
#[Timeout(3600)]
#[Tries(1)]
final class WatchPhotoProcessing implements ShouldQueue
{
    use Queueable;

    private const int OUT_OF_TOUCH = 10;

    public function __construct(public ProjectAsset $asset, public CarbonImmutable $since, public int $outOfTouch = 0)
    {
        $this->onConnection('conversions')->onQueue('conversions');
    }

    public function handle(NodeOdm $nodeOdm, Converter $converter): void
    {
        $task = $this->asset->options['nodeodm_task'] ?? null;

        if ($task === null || $this->asset->status !== ProjectAssetStatus::Processing) {
            return;
        }

        try {
            $info = $nodeOdm->info($task);
        } catch (ConversionFailed $conversionFailed) {
            if ($this->outOfTouch + 1 >= self::OUT_OF_TOUCH) {
                $this->fail($nodeOdm, $task, $conversionFailed->getMessage());

                return;
            }

            $this->again($this->outOfTouch + 1);

            return;
        }

        if ($this->since->addHours(config()->integer('meridian.photos.timeout_hours'))->isPast()) {
            $this->fail($nodeOdm, $task, __('NodeODM took longer than :hours hours, so it was stopped.', ['hours' => config()->integer('meridian.photos.timeout_hours')]));

            return;
        }

        match ($info['status']) {
            NodeOdm::COMPLETED => $this->finish($nodeOdm, $converter, $task, $info['minutes']),
            NodeOdm::FAILED, NodeOdm::CANCELED => $this->fail($nodeOdm, $task, __("NodeODM couldn't process the photos:")."\n".rescue(fn (): string => $nodeOdm->lastLines($task), __('(it said nothing more)'), report: false)),
            default => $this->carryOn($info['progress']),
        };
    }

    /**
     * Fails the upload when the worker gives up on the job (out of memory,
     * or killed), so it doesn't stay processing for good.
     */
    public function failed(?Throwable $exception): void
    {
        $task = $this->asset->options['nodeodm_task'] ?? null;

        if ($task !== null) {
            $this->fail(resolve(NodeOdm::class), $task, __('Meridian stopped following NodeODM unexpectedly. Retry it to process the photos again.'));
        }
    }

    private function carryOn(int $progress): void
    {
        $shown = 5 + intdiv(max(0, min(100, $progress)) * 65, 100);

        if ($shown !== $this->asset->progress) {
            $this->asset->update(['progress' => $shown]);
        }

        $this->again(0);
    }

    private function again(int $outOfTouch): void
    {
        dispatch(new self($this->asset, $this->since, $outOfTouch))->delay(config()->integer('meridian.photos.poll_seconds'));
    }

    private function finish(NodeOdm $nodeOdm, Converter $converter, string $task, int $minutes): void
    {
        $work = storage_path('app/conversions/'.$this->asset->id);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);

        try {
            $nodeOdm->download($task, $work.'/all.zip');
            $all = fopen($work.'/all.zip', 'rb');
            assert($all !== false);
            Storage::disk(config()->string('meridian.assets_disk'))->writeStream($this->asset->sourceKey(), $all);
        } catch (Throwable $throwable) {
            $this->fail($nodeOdm, $task, $throwable instanceof ConversionFailed ? $throwable->getMessage() : __("NodeODM's outputs couldn't be fetched."));

            return;
        } finally {
            File::deleteDirectory($work);
        }

        rescue(fn () => $nodeOdm->remove($task), report: false);
        $this->asset->update(['options' => [...Arr::except($this->asset->options ?? [], ['photos', 'quality', 'dtm', 'nodeodm_task']), 'opendronemap' => true]]);
        $converter->note($this->asset, 70, trans_choice('Processed by NodeODM in :count minute|Processed by NodeODM in :count minutes', $minutes));

        dispatch(new ConvertUpload($this->asset));
    }

    /**
     * Fails the upload, keeping its photos so it can be retried, and deletes
     * the task.
     */
    private function fail(NodeOdm $nodeOdm, string $task, string $why): void
    {
        rescue(fn () => $nodeOdm->remove($task), report: false);

        $this->asset->update([
            'status' => ProjectAssetStatus::Failed,
            'error' => $why,
            'options' => Arr::except($this->asset->options ?? [], ['nodeodm_task']),
        ]);
    }
}
