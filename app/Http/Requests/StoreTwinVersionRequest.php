<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ValidTwinDefinition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTwinVersionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'definition' => ['required', 'array', new ValidTwinDefinition()],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array<string, mixed> */
        return $this->validated('definition');
    }
}
