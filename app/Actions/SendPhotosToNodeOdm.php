<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Feature;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Jobs\WatchPhotoProcessing;
use App\Models\ProjectAsset;
use App\Support\Converter;
use App\Support\NodeOdm;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

final readonly class SendPhotosToNodeOdm
{
    /**
     * How many photos go in one request to NodeODM.
     */
    private const int BATCH = 10;

    /**
     * OpenDroneMap's options for each quality: how hard it looks for
     * features to match, and how dense a point cloud it builds.
     *
     * @var array<string, array<string, string>>
     */
    private const array QUALITY = [
        'fast' => ['feature-quality' => 'medium', 'pc-quality' => 'low'],
        'standard' => [],
        'high' => ['feature-quality' => 'ultra', 'pc-quality' => 'high'],
    ];

    public function __construct(private Converter $converter, private NodeOdm $nodeOdm) {}

    /**
     * Sends a zip of drone photos to NodeODM to be processed into a survey,
     * then leaves a job to follow it. The upload stays processing until
     * NodeODM is done; its outputs then come back as an OpenDroneMap export.
     *
     *     the organisation still has photo processing on
     *     the zip's photos (JPEG or TIFF, not in a dot-folder or __MACOSX),
     *         and any geo.txt or gcp_list.txt beside them; at least 5
     *     open a NodeODM task: a surface model always, a bare-ground model
     *         unless not asked, and the quality's options
     *     send the photos in batches of 10, straight from the zip, then start it
     */
    public function handle(ProjectAsset $asset): void
    {
        throw_unless(
            $asset->project->organisation->hasFeature(Feature::PhotoProcessing),
            ConversionFailed::class,
            __('Photo processing is off for this organisation, or this server no longer offers it.'),
        );

        $work = storage_path('app/conversions/'.$asset->id);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);
        $task = null;

        try {
            $asset->update(['status' => ProjectAssetStatus::Processing, 'error' => null]);
            $zip = $work.'/photos.zip';
            $this->converter->download(Storage::disk(config()->string('meridian.assets_disk')), $asset->sourceKey(), $zip);

            $archive = new ZipArchive;
            throw_unless($archive->open($zip, ZipArchive::RDONLY) === true, ConversionFailed::class, __("The zip file can't be read."));
            $photos = $this->photos($archive);
            $images = count(array_filter(array_keys($photos), fn (string $name): bool => ! str_ends_with(mb_strtolower($name), '.txt')));
            throw_if($images < 5, ConversionFailed::class, trans_choice('The zip has :count photo. Processing needs at least 5, taken with plenty of overlap.|The zip has :count photos. Processing needs at least 5, taken with plenty of overlap.', $images));

            $quality = $asset->options['quality'] ?? 'standard';
            $task = $this->nodeOdm->open($asset->name, [
                'dsm' => true,
                'dtm' => $asset->options['dtm'] ?? true,
                'auto-boundary' => true,
                ...self::QUALITY[$quality],
            ]);

            foreach (array_chunk($photos, self::BATCH, true) as $batch) {
                $this->nodeOdm->upload($task, array_map(function (string $name) use ($archive) {
                    $stream = $archive->getStream($name);
                    throw_if($stream === false, ConversionFailed::class, __("The zip file can't be read."));

                    return $stream;
                }, $batch));
            }

            $archive->close();
            $this->nodeOdm->commit($task);
        } catch (Throwable $throwable) {
            if ($task !== null) {
                rescue(fn () => $this->nodeOdm->remove($task), report: false);
            }

            throw $throwable;
        } finally {
            File::deleteDirectory($work);
        }

        $asset->update(['options' => [...$asset->options ?? [], 'nodeodm_task' => $task]]);
        $this->converter->note($asset, 5, __('Sent :count photos to NodeODM to process (:quality quality)', [
            'count' => number_format($images),
            'quality' => $quality,
        ]));

        dispatch(new WatchPhotoProcessing($asset, now()->toImmutable()))->delay(config()->integer('meridian.photos.poll_seconds'));
    }

    /**
     * The zip's photos, and any files that say where they were taken, each
     * by the name NodeODM gets and its name in the zip.
     *
     * @return array<string, string>
     */
    private function photos(ZipArchive $archive): array
    {
        $photos = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = (string) $archive->getNameIndex($index);
            $file = basename($name);

            if (preg_match('#(^|/)(\.|__MACOSX/)#', $name) === 1) {
                continue;
            }

            if (preg_match('/\.(jpe?g|tiff?)$/i', $file) === 1 || in_array(mb_strtolower($file), ['geo.txt', 'gcp_list.txt'], true)) {
                $photos[$file] ??= $name;
            }
        }

        return $photos;
    }
}
