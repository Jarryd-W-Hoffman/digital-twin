<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\PointCloudOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StartDirectUploadRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'filename' => ['required', 'string', 'max:200', 'regex:/^[^\/\\\\]+\.(glb|zip|obj|las|laz|e57|tif|tiff|asc|ifc|geojson|json|kml|kmz|dxf|gpkg)$/i'],
            'bytes' => ['required', 'integer', 'min:1', 'max:'.config()->integer('meridian.direct_uploads.max_upload_gb') * 1024 ** 3],
            ...PointCloudOptions::rules($this->project()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['filename.regex' => __('Upload a .glb model, a zipped 3D Tiles tileset, shapefile or photogrammetry export, an OBJ mesh, a LAS, LAZ or E57 point cloud, a GeoTIFF or ASCII grid, an IFC model, or a GeoJSON, KML, KMZ, DXF or GeoPackage map layer.')];
    }

    private function project(): Project
    {
        $project = $this->route('project');
        assert($project instanceof Project);

        return $project;
    }
}
