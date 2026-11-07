<?php

declare(strict_types=1);

namespace App\Http\Requests;

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
            'filename' => ['required', 'string', 'max:200', 'regex:/^[^\/\\\\]+\.(glb|zip)$/i'],
            'bytes' => ['required', 'integer', 'min:1', 'max:'.config()->integer('meridian.direct_uploads.max_upload_gb') * 1024 ** 3],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['filename.regex' => __('Upload a .glb model or a zipped 3D Tiles tileset.')];
    }
}
