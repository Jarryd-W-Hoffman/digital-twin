<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use Carbon\CarbonInterface;
use Database\Factories\ProjectAssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An upload in a project's library, which any of the project's twins can
 * show as a layer. Its directory is stored, not derived, so uploads made
 * before projects existed stay where they were written.
 *
 * @property-read string $id
 * @property-read string $project_id
 * @property-read ProjectAssetKind $kind
 * @property-read string $name
 * @property-read string $original_name
 * @property-read string $directory
 * @property-read string $entry
 * @property-read int $bytes
 * @property-read int $files
 * @property-read ProjectAssetStatus $status
 * @property-read string|null $error
 * @property-read string|null $upload_id
 * @property-read array{epsg?: int, spacing?: float, y_up?: bool, opendronemap?: true}|null $options
 * @property-read int|null $progress
 * @property-read string|null $log
 * @property-read string|null $crs
 * @property-read array{surface?: string, elements?: int, georeference?: array{lon: float, lat: float, headingDeg: float, source: string}|null, triangles?: int, origin?: array{lon: float, lat: float, height: float, source: string}|null, features?: int, bounds?: list<float>, zooms?: list<int>}|null $details
 * @property-read CarbonInterface|null $captured_on
 * @property-read Project $project
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class ProjectAsset extends Model
{
    /** @use HasFactory<ProjectAssetFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The heights a terrain or point cloud survey keeps beside its tiles,
     * as a GeoTIFF in its own coordinate system, for comparing surveys.
     */
    public const string SURFACE = 'surface.tif';

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'project_id' => 'string',
            'kind' => ProjectAssetKind::class,
            'name' => 'string',
            'original_name' => 'string',
            'directory' => 'string',
            'entry' => 'string',
            'bytes' => 'integer',
            'files' => 'integer',
            'status' => ProjectAssetStatus::class,
            'error' => 'string',
            'upload_id' => 'string',
            'options' => 'array',
            'progress' => 'integer',
            'log' => 'string',
            'crs' => 'string',
            'details' => 'array',
            'captured_on' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isReady(): bool
    {
        return $this->status === ProjectAssetStatus::Ready;
    }

    /**
     * Where a direct upload's bytes land before they are checked and unpacked:
     * apart from the asset's files, so a failed unpack can't take them too.
     */
    public function sourceKey(): string
    {
        return 'uploads/'.$this->id.'/'.$this->original_name;
    }

    /**
     * The URL the viewer loads: the model file, or the tileset's root JSON.
     */
    public function url(): string
    {
        return route('assets.files.show', [$this->id, $this->entry], absolute: false);
    }
}
