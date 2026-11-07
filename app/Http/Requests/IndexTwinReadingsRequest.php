<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class IndexTwinReadingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'asset' => ['required', 'string', 'max:64'],
            'metric' => ['required', 'string', 'regex:/^[a-z][A-Za-z0-9_]{0,63}$/'],
            'from' => ['required', 'integer', 'min:0'],
            'to' => ['required', 'integer', 'gt:from', 'lte:'.(now()->getTimestampMs() + 86_400_000)],
            'buckets' => ['nullable', 'integer', 'between:10,1000'],
        ];
    }
}
