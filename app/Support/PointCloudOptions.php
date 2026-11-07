<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How a point cloud or DEM is converted: the EPSG code of its coordinates
 * when the file doesn't say, and (point clouds only) a spacing in metres to
 * thin it to.
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
        ];
    }

    /**
     * @return array{epsg?: int, spacing?: float}
     */
    public static function from(Request $request): array
    {
        return array_filter([
            'epsg' => $request->filled('epsg') ? $request->integer('epsg') : null,
            'spacing' => $request->filled('spacing') ? $request->float('spacing') : null,
        ], fn (int|float|null $value): bool => $value !== null);
    }
}
