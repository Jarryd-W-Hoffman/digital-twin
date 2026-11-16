<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How an upload is converted: the EPSG code of its coordinates when the file
 * doesn't say, (point clouds only) a spacing in metres to thin it to, (meshes
 * in local metres only) whether y is up, and what an ambiguous file is: a
 * GeoTIFF as `imagery`, a zip as a `vector`, a `mesh` or an `opendronemap`
 * export (a mesh, split into its other outputs first). Also when it was
 * captured, which is kept on the upload rather than among its options.
 */
final class PointCloudOptions
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'epsg' => ['nullable', 'integer', 'between:1024,999999'],
            'spacing' => ['nullable', 'numeric', 'between:0.001,100'],
            'as' => ['nullable', 'string', 'in:imagery,vector,mesh,opendronemap'],
            'y_up' => ['nullable', 'boolean'],
            'captured_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array{epsg?: int, spacing?: float, as?: string, y_up?: bool, captured_on?: string}
     */
    public static function from(Request $request): array
    {
        return array_filter([
            'epsg' => $request->filled('epsg') ? $request->integer('epsg') : null,
            'spacing' => $request->filled('spacing') ? $request->float('spacing') : null,
            'as' => $request->filled('as') ? $request->string('as')->value() : null,
            'y_up' => $request->boolean('y_up') ? true : null,
            'captured_on' => $request->filled('captured_on') ? $request->string('captured_on')->value() : null,
        ], fn (int|float|string|bool|null $value): bool => $value !== null);
    }

    /**
     * The options kept on the upload for its conversion: not what it is or
     * when it was captured, which are kept as its kind and date, but whether
     * it's an OpenDroneMap export still to be split.
     *
     * @param  array{epsg?: int, spacing?: float, as?: string, y_up?: bool, captured_on?: string}  $options
     * @return array{epsg?: int, spacing?: float, y_up?: bool, opendronemap?: true}
     */
    public static function forConversion(array $options): array
    {
        $isOpenDroneMap = ($options['as'] ?? null) === 'opendronemap';
        unset($options['as'], $options['captured_on']);

        return $isOpenDroneMap ? [...$options, 'opendronemap' => true] : $options;
    }
}
