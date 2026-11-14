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
 * Turns an uploaded georeferenced image (a GeoTIFF orthophoto or drone
 * mosaic) into web map tiles a twin lays over the ground:
 *
 *     check the file's signature
 *     meridian-imagery → reproject to Web Mercator, scale to 8 bits, write
 *                        {z}/{x}/{y}.png and imagery.json (zooms and bounds)
 *     copy the tiles into the asset's directory, then drop the original
 *
 * On failure the original is kept, so the conversion can be retried (say,
 * with the EPSG code an image without a coordinate system needs).
 */
final readonly class ConvertImagery
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

            $source = $work.'/source.tif';
            $this->converter->download($disk, $asset->sourceKey(), $source);
            throw_unless(
                in_array(mb_substr($this->converter->header($source, 4), 0, 4, '8bit'), ["II*\0", "MM\0*", "II+\0", "MM\0+"], true),
                ConversionFailed::class,
                __('That is not a GeoTIFF image.'),
            );
            $this->converter->note($asset, 5, __('Downloaded :name', ['name' => $asset->original_name]));

            $tiles = $work.'/tiles';
            $summary = $this->tile($asset, $source, $tiles);
            $this->converter->note($asset, 85, __(':crs, :width × :height pixels, :tiles tiles at zooms :min to :max', [
                'crs' => $summary['crs'],
                'width' => number_format($summary['width']),
                'height' => number_format($summary['height']),
                'tiles' => number_format($summary['tiles']),
                'min' => $summary['minZoom'],
                'max' => $summary['maxZoom'],
            ]));

            $disk->deleteDirectory($asset->directory);
            $stored = $this->converter->store($disk, $tiles, $asset->directory, 'imagery.json');
            $disk->deleteDirectory(dirname($asset->sourceKey()));

            $asset->update([
                ...$stored,
                'crs' => $summary['crs'],
                'details' => ['bounds' => $summary['bounds'], 'zooms' => [$summary['minZoom'], $summary['maxZoom']]],
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
     * @return array{crs: string, width: int, height: int, tiles: int, minZoom: int, maxZoom: int, bounds: list<float>}
     */
    private function tile(ProjectAsset $asset, string $source, string $tiles): array
    {
        $command = [
            config()->string('meridian.imagery.tiler'), $source, $tiles,
            '--jobs', (string) config()->integer('meridian.imagery.jobs'),
        ];

        if (isset($asset->options['epsg'])) {
            $command = [...$command, '--srs', 'EPSG:'.$asset->options['epsg']];
        }

        $output = $this->converter->run($command, __('The image could not be tiled'), config()->integer('meridian.imagery.timeout_minutes'));

        throw_unless(File::exists($tiles.'/imagery.json'), ConversionFailed::class, __('meridian-imagery wrote no imagery.json.'));

        $lines = array_filter(explode("\n", mb_trim($output)));
        /** @var array{crs?: string, width?: int, height?: int, tiles?: int, minZoom?: int, maxZoom?: int, bounds?: list<float>} $summary */
        $summary = json_decode((string) end($lines), true) ?: [];

        return [
            'crs' => $summary['crs'] ?? 'Unknown',
            'width' => (int) ($summary['width'] ?? 0),
            'height' => (int) ($summary['height'] ?? 0),
            'tiles' => (int) ($summary['tiles'] ?? 0),
            'minZoom' => (int) ($summary['minZoom'] ?? 0),
            'maxZoom' => (int) ($summary['maxZoom'] ?? 0),
            'bounds' => $summary['bounds'] ?? [],
        ];
    }
}
