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
 * Turns an uploaded LAS, LAZ or E57 scan into a 3D Tiles tileset:
 *
 *     check the file's signature
 *     pdal info       → point count and coordinate system
 *     pdal pipeline   → read, assign the given EPSG, thin, write plain LAS
 *     py3dtiles       → tile, reprojected to ECEF when the CRS is known
 *     copy the tiles into the asset's directory, then drop the original
 *
 * Progress and a short log are written to the asset as it goes. On failure
 * the original is kept, so the conversion can be retried.
 */
final readonly class ConvertPointCloud
{
    public function __construct(private Converter $converter) {}

    public function handle(ProjectAsset $asset): ProjectAsset
    {
        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $work = storage_path('app/conversions/'.$asset->id);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);

        try {
            $asset->update(['status' => ProjectAssetStatus::Processing, 'progress' => 0, 'error' => null, 'log' => null]);

            $source = $work.'/source.'.mb_strtolower(pathinfo($asset->original_name, PATHINFO_EXTENSION));
            $this->converter->download($disk, $asset->sourceKey(), $source);
            $this->checkSignature($source);
            $this->converter->note($asset, 5, __('Downloaded :name', ['name' => $asset->original_name]));

            [$points, $crs] = $this->inspect($asset, $source);
            $this->converter->note($asset, 15, __(':points points, :crs', [
                'points' => number_format($points),
                'crs' => $crs ?? __('no coordinate system (placed as local metres)'),
            ]));

            $prepared = $this->prepare($asset, $source, $work);
            $this->converter->note($asset, 45, __('Prepared for tiling'));

            $tiles = $this->tile($prepared, $work, $crs !== null);
            $this->converter->note($asset, 85, __('Tiled'));

            $disk->deleteDirectory($asset->directory);
            $stored = $this->converter->store($disk, $tiles, $asset->directory, 'tileset.json');
            $disk->deleteDirectory(dirname($asset->sourceKey()));

            $asset->update([...$stored, 'crs' => $crs, 'status' => ProjectAssetStatus::Ready, 'progress' => 100, 'error' => null]);
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
     * LAS and LAZ start "LASF"; E57 starts "ASTM-E57".
     */
    private function checkSignature(string $path): void
    {
        $header = $this->converter->header($path, 8);

        throw_unless(
            str_starts_with($header, 'LASF') || $header === 'ASTM-E57',
            ConversionFailed::class,
            __('That is not a LAS, LAZ or E57 point cloud.'),
        );
    }

    /**
     * @return array{0: int, 1: string|null} Point count and CRS label.
     */
    private function inspect(ProjectAsset $asset, string $source): array
    {
        $output = $this->run([config()->string('meridian.point_clouds.pdal'), 'info', '--summary', $source], __('PDAL could not read the file'));

        /** @var array{summary?: array{num_points?: int, srs?: array{json?: array{name?: string, id?: array{authority?: string, code?: int|string}}}}} $info */
        $info = json_decode($output, true) ?: [];
        $points = (int) ($info['summary']['num_points'] ?? 0);

        throw_if($points === 0, ConversionFailed::class, __('The file has no points.'));

        $epsg = $asset->options['epsg'] ?? null;

        if ($epsg !== null) {
            return [$points, 'EPSG:'.$epsg];
        }

        $srs = $info['summary']['srs']['json'] ?? null;

        if ($srs === null) {
            return [$points, null];
        }

        $id = $srs['id'] ?? null;

        return [$points, isset($id['authority'], $id['code']) ? $id['authority'].':'.$id['code'] : ($srs['name'] ?? 'Custom')];
    }

    private function prepare(ProjectAsset $asset, string $source, string $work): string
    {
        $prepared = $work.'/prepared.las';
        $reader = ['filename' => $source];

        if (isset($asset->options['epsg'])) {
            $reader['override_srs'] = 'EPSG:'.$asset->options['epsg'];
        }

        $stages = [$reader];

        if (isset($asset->options['spacing'])) {
            $stages[] = ['type' => 'filters.voxelcenternearestneighbor', 'cell' => $asset->options['spacing']];
        }

        $stages[] = ['type' => 'writers.las', 'filename' => $prepared];
        File::put($work.'/pipeline.json', (string) json_encode($stages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->run([config()->string('meridian.point_clouds.pdal'), 'pipeline', $work.'/pipeline.json'], __('PDAL could not prepare the points'));

        return $prepared;
    }

    /**
     * LAS stores easting as X whatever the CRS says about axis order, hence
     * --pyproj-always-xy; without it, northing-first systems such as NZTM
     * land on the wrong side of the planet.
     */
    private function tile(string $prepared, string $work, bool $georeferenced): string
    {
        $tiles = $work.'/tiles';
        $command = [
            config()->string('meridian.point_clouds.py3dtiles'), 'convert', $prepared,
            '--out', $tiles,
            '--overwrite',
            '--jobs', (string) config()->integer('meridian.point_clouds.jobs'),
            '--pyproj-always-xy',
        ];

        $this->run($georeferenced ? [...$command, '--srs_out', '4978'] : $command, __('py3dtiles could not tile the points'));

        throw_unless(File::exists($tiles.'/tileset.json'), ConversionFailed::class, __('py3dtiles wrote no tileset.json.'));

        return $tiles;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $failure): string
    {
        return $this->converter->run($command, $failure, config()->integer('meridian.point_clouds.timeout_minutes'));
    }
}
