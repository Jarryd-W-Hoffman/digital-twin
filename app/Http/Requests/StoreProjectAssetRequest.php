<?php

declare(strict_types=1);

namespace App\Http\Requests;

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
            'file' => ['required', 'file', 'extensions:glb,zip,las,laz,e57,tif,tiff,asc,ifc', 'max:'.config()->integer('meridian.max_upload_kb')],
            ...PointCloudOptions::rules(),
        ];
    }
}
