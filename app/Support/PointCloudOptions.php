<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Feature;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;

/**
 * How an upload is converted: the EPSG code of its coordinates when the file
 * doesn't say, (point clouds only) a spacing in metres to thin it to, (meshes
 * in local metres only) whether y is up, and what an ambiguous file is: a
 * GeoTIFF as `imagery`, a zip as a `vector`, a `mesh`, an `opendronemap`
 * export (a mesh, split into its other outputs first) or drone `photos` to
 * process into one (at a `quality`, with a bare-ground model unless `dtm` is
 * false). Also when it was
 * captured, which is kept on the upload rather than among its options.
 */
final class PointCloudOptions
{
    /**
     * @param  Project|null  $project  Where it's uploaded to, whose organisation must have photo processing on for photos.
     * @return array<string, list<string|Closure>>
     */
    public static function rules(?Project $project = null): array
    {
        return [
            'epsg' => ['nullable', 'integer', 'between:1024,999999'],
            'spacing' => ['nullable', 'numeric', 'between:0.001,100'],
            'as' => ['nullable', 'string', 'in:imagery,vector,mesh,opendronemap,photos', function (string $attribute, mixed $value, Closure $fail) use ($project): void {
                if ($value === 'photos' && $project instanceof Project && ! $project->organisation->hasFeature(Feature::PhotoProcessing)) {
                    $fail(__('Photo processing is off for this organisation. Its owners and admins turn it on under Features.'));
                }
            }],
            'y_up' => ['nullable', 'boolean'],
            'quality' => ['nullable', 'string', 'in:fast,standard,high'],
            'dtm' => ['nullable', 'boolean'],
            'captured_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array{epsg?: int, spacing?: float, as?: string, y_up?: bool, quality?: string, dtm?: bool, captured_on?: string}
     */
    public static function from(Request $request): array
    {
        return array_filter([
            'epsg' => $request->filled('epsg') ? $request->integer('epsg') : null,
            'spacing' => $request->filled('spacing') ? $request->float('spacing') : null,
            'as' => $request->filled('as') ? $request->string('as')->value() : null,
            'y_up' => $request->boolean('y_up') ? true : null,
            'quality' => $request->filled('quality') ? $request->string('quality')->value() : null,
            'dtm' => $request->has('dtm') ? $request->boolean('dtm') : null,
            'captured_on' => $request->filled('captured_on') ? $request->string('captured_on')->value() : null,
        ], fn (int|float|string|bool|null $value): bool => $value !== null);
    }

    /**
     * The options kept on the upload for its conversion: not what it is or
     * when it was captured, which are kept as its kind and date, but whether
     * it's an OpenDroneMap export still to be split, or drone photos still to
     * be processed (and how).
     *
     * @param  array{epsg?: int, spacing?: float, as?: string, y_up?: bool, quality?: string, dtm?: bool, captured_on?: string}  $options
     * @return array{epsg?: int, spacing?: float, y_up?: bool, quality?: string, dtm?: bool, opendronemap?: true, photos?: true}
     */
    public static function forConversion(array $options): array
    {
        $as = $options['as'] ?? null;
        unset($options['as'], $options['captured_on']);

        if ($as === 'photos') {
            return [...$options, 'photos' => true];
        }

        unset($options['quality'], $options['dtm']);

        return $as === 'opendronemap' ? [...$options, 'opendronemap' => true] : $options;
    }
}
