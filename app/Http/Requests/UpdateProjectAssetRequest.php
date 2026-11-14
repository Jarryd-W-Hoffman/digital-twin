<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\PointCloudOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateProjectAssetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['captured_on' => PointCloudOptions::rules()['captured_on']];
    }
}
