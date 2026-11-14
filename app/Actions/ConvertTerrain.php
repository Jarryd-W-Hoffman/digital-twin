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
 * Turns an uploaded DEM (GeoTIFF or ESRI ASCII grid) into quantized-mesh
 * terrain, which a twin can show as its ground:
 *
 *     check the file's signature
 *     meridian-terrain → reproject to WGS84, triangulate each tile, write
 *                        {z}/{x}/{y}.terrain and layer.json
 *     copy the tiles, and the heights as given (for comparing surveys), into
 *     the asset's directory, then drop the original
 *
 * On failure the original is kept, so the conversion can be retried (say,
 * with the EPSG code a DEM without a coordinate system needs).
 */
final readonly class ConvertTerrain
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

            $tiles = $work.'/tiles';
            $summary = $this->tile($asset, $source, $tiles);
            $this->converter->note($asset, 85, __(':crs, :tiles tiles to zoom :zoom, heights :low to :high m', [
                'crs' => $summary['crs'],
                'tiles' => number_format($summary['tiles']),
                'zoom' => $summary['maxZoom'],
                'low' => $summary['heights'][0],
                'high' => $summary['heights'][1],
            ]));

            $disk->deleteDirectory($asset->directory);
            $stored = $this->converter->store($disk, $tiles, $asset->directory, 'layer.json');
            $disk->deleteDirectory(dirname($asset->sourceKey()));

            $asset->update([...$stored, 'crs' => $summary['crs'], 'details' => ['surface' => ProjectAsset::SURFACE], 'status' => ProjectAssetStatus::Ready, 'progress' => 100, 'error' => null]);
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
     * TIFF starts "II" or "MM" then 42 (or 43 for BigTIFF); an ASCII grid
     * starts with its "ncols" header.
     */
    private function checkSignature(string $path): void
    {
        $header = $this->converter->header($path, 8);

        throw_unless(
            in_array(mb_substr($header, 0, 4, '8bit'), ["II*\0", "MM\0*", "II+\0", "MM\0+"], true)
                || str_starts_with(mb_strtolower(mb_ltrim($header)), 'ncols'),
            ConversionFailed::class,
            __('That is not a GeoTIFF or ASCII grid elevation model.'),
        );
    }

    /**
     * @return array{crs: string, tiles: int, maxZoom: int, heights: array{0: float, 1: float}}
     */
    private function tile(ProjectAsset $asset, string $source, string $tiles): array
    {
        $command = [
            config()->string('meridian.terrain.tiler'), $source, $tiles,
            '--jobs', (string) config()->integer('meridian.terrain.jobs'),
            '--surface', $tiles.'/'.ProjectAsset::SURFACE,
        ];

        if (isset($asset->options['epsg'])) {
            $command = [...$command, '--srs', 'EPSG:'.$asset->options['epsg']];
        }

        $output = $this->converter->run($command, __('The DEM could not be tiled'), config()->integer('meridian.terrain.timeout_minutes'));

        throw_unless(File::exists($tiles.'/layer.json'), ConversionFailed::class, __('meridian-terrain wrote no layer.json.'));

        $lines = array_filter(explode("\n", mb_trim($output)));
        /** @var array{crs?: string, tiles?: int, maxZoom?: int, heights?: array{0: float, 1: float}} $summary */
        $summary = json_decode((string) end($lines), true) ?: [];

        return [
            'crs' => $summary['crs'] ?? 'Unknown',
            'tiles' => (int) ($summary['tiles'] ?? 0),
            'maxZoom' => (int) ($summary['maxZoom'] ?? 0),
            'heights' => $summary['heights'] ?? [0.0, 0.0],
        ];
    }
}
