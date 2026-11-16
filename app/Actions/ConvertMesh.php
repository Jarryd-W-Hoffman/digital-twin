<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use App\Support\Converter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns an uploaded photogrammetry mesh (an OBJ, or a zipped export with its
 * MTL and textures) into a 3D Tiles tileset:
 *
 *     meridian-mesh → find the OBJ and where it is (the EPSG code given,
 *                     metadata.xml, OpenDroneMap's coords.txt, or an
 *                     offset file and .prj), reproject
 *                     every vertex to metres east, north and up of its centre,
 *                     and tile it with Obj2Tiles in levels of detail
 *     copy the tiles into the asset's directory, then drop the original
 *
 * An OpenDroneMap export is split first: its other outputs become library
 * items of their own, and it becomes its textured 3D model.
 *
 * A mesh that says nothing of where it is comes out in local metres, to be
 * placed in a twin. On failure the original is kept, so the conversion can
 * be retried (say, with the EPSG code it was missing).
 */
final readonly class ConvertMesh
{
    public function __construct(private Converter $converter, private SplitOpenDroneMapExport $split) {}

    public function handle(ProjectAsset $asset): ProjectAsset
    {
        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $work = storage_path('app/conversions/'.$asset->id);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);

        try {
            $asset->update(['status' => ProjectAssetStatus::Processing, 'progress' => 0, 'error' => null, 'log' => null]);

            $extension = mb_strtolower(pathinfo($asset->original_name, PATHINFO_EXTENSION));
            $source = $work.'/source.'.$extension;
            $this->converter->download($disk, $asset->sourceKey(), $source);
            throw_if(
                $extension === 'zip' && $this->converter->header($source, 4) !== "PK\x03\x04",
                ConversionFailed::class,
                __('That is not a zip file.'),
            );
            $this->converter->note($asset, 5, __('Downloaded :name', ['name' => $asset->original_name]));

            if ($asset->options['opendronemap'] ?? false) {
                $added = $this->split->handle($asset, $source, $work);
                $this->converter->note($asset, 10, $added === []
                    ? __('An OpenDroneMap export: its 3D model only')
                    : __('An OpenDroneMap export: added :names to the library', ['names' => collect($added)->map(fn (ProjectAsset $part): string => $part->name)->join(', ', ' and ')]));
            }

            $out = $work.'/tiles';
            $summary = $this->convert($asset, $source, $out);
            $this->converter->note($asset, 85, __(':obj: :triangles triangles, :x × :y × :z m, in :tiles tiles', [
                'obj' => $summary['obj'],
                'triangles' => number_format($summary['triangles']),
                'x' => $summary['size'][0],
                'y' => $summary['size'][1],
                'z' => $summary['size'][2],
                'tiles' => number_format($summary['tiles']),
            ]).($summary['textured'] ? '' : __(', untextured: its materials (MTL) were not uploaded with it')));
            $georeference = $summary['georeference'];
            $this->converter->note($asset, 90, $georeference === null
                ? __("Not georeferenced: it starts at a twin's origin, to be placed")
                : __('Georeferenced by :source (:crs)', ['source' => $georeference['source'], 'crs' => $summary['crs']]));

            $disk->deleteDirectory($asset->directory);
            $stored = $this->converter->store($disk, $out, $asset->directory, 'tileset.json');
            $disk->deleteDirectory(dirname($asset->sourceKey()));

            $asset->update([
                ...$stored,
                'crs' => $summary['crs'],
                'details' => ['triangles' => $summary['triangles'], 'origin' => $georeference],
                'status' => ProjectAssetStatus::Ready,
                'progress' => 100,
                'error' => null,
            ]);
            $this->converter->note($asset, 100, __(':files files written', ['files' => number_format($stored['files'])]));

            return $asset;
        } catch (Throwable $throwable) {
            $disk->deleteDirectory($asset->directory);

            throw $throwable;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * @return array{obj: string, triangles: int, size: array{0: float, 1: float, 2: float}, tiles: int, textured: bool, crs: string|null, georeference: array{lon: float, lat: float, height: float, source: string}|null}
     */
    private function convert(ProjectAsset $asset, string $source, string $out): array
    {
        $command = [
            config()->string('meridian.mesh.converter'), $source, $out,
            '--max-bytes', (string) (config()->integer('meridian.mesh.max_extracted_mb') * 1024 * 1024),
        ];

        if (isset($asset->options['epsg'])) {
            $command = [...$command, '--epsg', (string) $asset->options['epsg']];
        }

        if ($asset->options['y_up'] ?? false) {
            $command[] = '--y-up';
        }

        $output = $this->converter->run($command, __('The mesh could not be converted'), config()->integer('meridian.mesh.timeout_minutes'));

        throw_unless(File::exists($out.'/tileset.json'), ConversionFailed::class, __('meridian-mesh wrote no tileset.json.'));

        $lines = array_filter(explode("\n", mb_trim($output)));
        /** @var array{obj?: string, triangles?: int, size?: array{0: float, 1: float, 2: float}, tiles?: int, textured?: bool, crs?: string|null, georeference?: array{lon: float, lat: float, height: float, source: string}|null} $summary */
        $summary = json_decode((string) end($lines), true) ?: [];

        return [
            'obj' => $summary['obj'] ?? 'mesh',
            'triangles' => (int) ($summary['triangles'] ?? 0),
            'size' => $summary['size'] ?? [0.0, 0.0, 0.0],
            'tiles' => (int) ($summary['tiles'] ?? 0),
            'textured' => $summary['textured'] ?? true,
            'crs' => $summary['crs'] ?? null,
            'georeference' => $summary['georeference'] ?? null,
        ];
    }
}
