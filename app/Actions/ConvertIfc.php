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
 * Turns an uploaded IFC model into one GLB a twin can place, keeping what
 * each element is:
 *
 *     check the file's signature
 *     meridian-ifc → triangulate every element, write model.glb (a node per
 *                    element, named by its GlobalId) and elements.json, and
 *                    say where the model is when the file does
 *     copy both into the asset's directory, then drop the original
 *
 * On failure the original is kept, so the conversion can be retried.
 */
final readonly class ConvertIfc
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

            $source = $work.'/source.ifc';
            $this->converter->download($disk, $asset->sourceKey(), $source);
            throw_unless(
                str_starts_with(mb_ltrim($this->converter->header($source, 64)), 'ISO-10303-21;'),
                ConversionFailed::class,
                __('That is not an IFC file (STEP, starting ISO-10303-21).'),
            );
            $this->converter->note($asset, 5, __('Downloaded :name', ['name' => $asset->original_name]));

            $out = $work.'/model';
            $summary = $this->convert($out);
            $this->converter->note($asset, 80, __(':schema, :elements elements, :triangles triangles, :x × :y × :z m', [
                'schema' => $summary['schema'],
                'elements' => number_format($summary['elements']),
                'triangles' => number_format($summary['triangles']),
                'x' => $summary['size'][0],
                'y' => $summary['size'][1],
                'z' => $summary['size'][2],
            ]));
            $georeference = $summary['georeference'];
            $this->converter->note($asset, 85, $georeference === null
                ? __("Not georeferenced: it starts at a twin's origin, to be placed")
                : __('Georeferenced by :source:crs', [
                    'source' => $georeference['source'],
                    'crs' => $summary['crs'] === null ? '' : ' ('.$summary['crs'].')',
                ]));

            $disk->deleteDirectory($asset->directory);
            $stored = $this->converter->store($disk, $out, $asset->directory, 'model.glb');
            $disk->deleteDirectory(dirname($asset->sourceKey()));

            $asset->update([
                ...$stored,
                'crs' => $summary['crs'],
                'details' => ['elements' => $summary['elements'], 'georeference' => $georeference],
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
     * @return array{schema: string, elements: int, triangles: int, size: array{0: float, 1: float, 2: float}, crs: string|null, georeference: array{lon: float, lat: float, headingDeg: float, source: string}|null}
     */
    private function convert(string $out): array
    {
        $output = $this->converter->run(
            [config()->string('meridian.ifc.converter'), dirname($out).'/source.ifc', $out],
            __('The IFC model could not be converted'),
            config()->integer('meridian.ifc.timeout_minutes'),
        );

        throw_unless(File::exists($out.'/model.glb'), ConversionFailed::class, __('meridian-ifc wrote no model.glb.'));

        $lines = array_filter(explode("\n", mb_trim($output)));
        /** @var array{schema?: string, elements?: int, triangles?: int, size?: array{0: float, 1: float, 2: float}, crs?: string|null, georeference?: array{lon: float, lat: float, headingDeg: float, source: string}|null} $summary */
        $summary = json_decode((string) end($lines), true) ?: [];

        return [
            'schema' => $summary['schema'] ?? 'IFC',
            'elements' => (int) ($summary['elements'] ?? 0),
            'triangles' => (int) ($summary['triangles'] ?? 0),
            'size' => $summary['size'] ?? [0.0, 0.0, 0.0],
            'crs' => $summary['crs'] ?? null,
            'georeference' => $summary['georeference'] ?? null,
        ];
    }
}
