<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\ViewState;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming a view, sharing it or not, or saving what is on screen over it.
 */
final class UpdateSavedViewRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'shared' => ['sometimes', 'boolean'],
            ...ViewState::rules(required: false),
        ];
    }
}
