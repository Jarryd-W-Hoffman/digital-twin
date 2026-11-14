<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How an upload is converted: the EPSG code of its coordinates when the file
 * doesn't say, (point clouds only) a spacing in metres to thin it to, and
 * what an ambiguous file is: a GeoTIFF as `imagery`, a zip as a `vector`.
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
            'as' => ['nullable', 'string', 'in:imagery,vector'],
        ];
    }

    /**
     * @return array{epsg?: int, spacing?: float, as?: string}
     */
    public static function from(Request $request): array
    {
        return array_filter([
            'epsg' => $request->filled('epsg') ? $request->integer('epsg') : null,
            'spacing' => $request->filled('spacing') ? $request->float('spacing') : null,
            'as' => $request->filled('as') ? $request->string('as')->value() : null,
        ], fn (int|float|string|null $value): bool => $value !== null);
    }
}
