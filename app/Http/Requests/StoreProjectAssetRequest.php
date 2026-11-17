<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Twin;
use App\Support\PointCloudOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectAssetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file', 'extensions:glb,zip,obj,las,laz,e57,tif,tiff,asc,ifc,geojson,json,kml,kmz,dxf,gpkg', 'max:'.config()->integer('meridian.max_upload_kb')],
            ...PointCloudOptions::rules($this->project()),
        ];
    }

    /**
     * The project it's uploaded to: the one in the address, or the twin's.
     */
    private function project(): ?Project
    {
        $project = $this->route('project');
        $twin = $this->route('twin');

        return $project instanceof Project ? $project : ($twin instanceof Twin ? $twin->project : null);
    }
}
