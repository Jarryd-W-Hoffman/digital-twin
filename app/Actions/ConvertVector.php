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
 * Turns an uploaded map layer (GeoJSON, KML or KMZ, a zipped shapefile, DXF
 * or a GeoPackage) into GeoJSON a twin drapes on the ground:
 *
 *     meridian-vector → read every layer, reproject each feature to WGS84
 *                       (from the EPSG code given when the file has none),
 *                       drop heights, write layer.geojson
 *     copy it into the asset's directory, then drop the original
 *
 * On failure the original is kept, so the conversion can be retried (say,
 * with the EPSG code a DXF drawing needs).
 */
final readonly class ConvertVector
{
    public function __construct(private Converter $converter) {}

    public function handle(ProjectAsset $asset): ProjectAsset
    {
        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $work = storage_path('app/conversions/'.$asset->id);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work.'/layer');

        try {
            $asset->update(['status' => ProjectAssetStatus::Processing, 'progress' => 0, 'error' => null, 'log' => null]);

            $source = $work.'/source.'.mb_strtolower(pathinfo($asset->original_name, PATHINFO_EXTENSION));
            $this->converter->download($disk, $asset->sourceKey(), $source);
            $this->converter->note($asset, 5, __('Downloaded :name', ['name' => $asset->original_name]));

            $summary = $this->convert($asset, $source, $work.'/layer/layer.geojson');
            $this->converter->note($asset, 80, __(':crs, :features features in :layers layers (:kinds)', [
                'crs' => $summary['crs'],
                'features' => number_format($summary['features']),
                'layers' => $summary['layers'],
                'kinds' => collect($summary['kinds'])->map(fn (int $count, string $kind): string => $count.' '.$kind)->implode(', '),
            ]));

            $disk->deleteDirectory($asset->directory);
            $stored = $this->converter->store($disk, $work.'/layer', $asset->directory, 'layer.geojson');
            $disk->deleteDirectory(dirname($asset->sourceKey()));

            $asset->update([
                ...$stored,
                'crs' => $summary['crs'],
                'details' => ['features' => $summary['features'], 'bounds' => $summary['bounds']],
                'status' => ProjectAssetStatus::Ready,
                'progress' => 100,
                'error' => null,
            ]);
            $this->converter->note($asset, 100, __('GeoJSON written'));

            return $asset;
        } catch (Throwable $throwable) {
            $disk->deleteDirectory($asset->directory);

            throw $throwable;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * @return array{crs: string, features: int, layers: int, kinds: array<string, int>, bounds: list<float>}
     */
    private function convert(ProjectAsset $asset, string $source, string $out): array
    {
        $command = [
            config()->string('meridian.vector.converter'), $source, $out,
            '--max-features', (string) config()->integer('meridian.vector.max_features'),
            '--name', pathinfo($asset->original_name, PATHINFO_FILENAME),
        ];

        if (isset($asset->options['epsg'])) {
            $command = [...$command, '--srs', 'EPSG:'.$asset->options['epsg']];
        }

        $output = $this->converter->run($command, __('The map layer could not be read'), config()->integer('meridian.vector.timeout_minutes'));

        throw_unless(File::exists($out), ConversionFailed::class, __('meridian-vector wrote no layer.geojson.'));

        $lines = array_filter(explode("\n", mb_trim($output)));
        /** @var array{crs?: string, features?: int, layers?: int, kinds?: array<string, int>, bounds?: list<float>} $summary */
        $summary = json_decode((string) end($lines), true) ?: [];

        return [
            'crs' => $summary['crs'] ?? 'Unknown',
            'features' => (int) ($summary['features'] ?? 0),
            'layers' => (int) ($summary['layers'] ?? 0),
            'kinds' => $summary['kinds'] ?? [],
            'bounds' => $summary['bounds'] ?? [],
        ];
    }
}
