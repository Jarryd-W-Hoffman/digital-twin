<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class SignDirectUploadPartsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parts' => ['required', 'array', 'min:1', 'max:100'],
            'parts.*' => ['integer', 'between:1,10000'],
        ];
    }

    /**
     * @return list<int>
     */
    public function parts(): array
    {
        /** @var list<int|string> $parts */
        $parts = $this->array('parts');

        return array_map(intval(...), $parts);
    }
}
