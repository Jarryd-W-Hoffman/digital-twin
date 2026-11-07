<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class CompleteDirectUploadRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parts' => ['required', 'array', 'min:1', 'max:10000'],
            'parts.*.number' => ['required', 'integer', 'between:1,10000', 'distinct'],
            'parts.*.etag' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return list<array{PartNumber: int, ETag: string}>
     */
    public function parts(): array
    {
        /** @var list<array{number: int|string, etag: string}> $parts */
        $parts = $this->array('parts');

        return array_values(collect($parts)
            ->map(fn (array $part): array => ['PartNumber' => (int) $part['number'], 'ETag' => $part['etag']])
            ->sortBy('PartNumber')
            ->all());
    }
}
