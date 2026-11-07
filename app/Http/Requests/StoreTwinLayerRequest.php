<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectAssetStatus;
use App\Models\ProjectAsset;
use App\Models\Twin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTwinLayerRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $twin = $this->route('twin');
        assert($twin instanceof Twin);

        return [
            'asset' => ['required', 'uuid', Rule::exists(ProjectAsset::class, 'id')->where('project_id', $twin->project_id)->where('status', ProjectAssetStatus::Ready->value)],
        ];
    }

    public function asset(): ProjectAsset
    {
        return ProjectAsset::query()->findOrFail($this->string('asset')->value());
    }
}
