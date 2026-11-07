<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AssetType;
use App\Models\Twin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTwinTypeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $twin = $this->route('twin');
        assert($twin instanceof Twin);

        return [
            'type' => ['required', 'uuid', Rule::exists(AssetType::class, 'id')->where('organisation_id', $twin->organisation_id)],
        ];
    }

    public function assetType(): AssetType
    {
        return AssetType::query()->findOrFail($this->string('type')->value());
    }
}
