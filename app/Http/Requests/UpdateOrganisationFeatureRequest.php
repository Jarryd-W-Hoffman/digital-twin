<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Feature;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateOrganisationFeatureRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $feature = $this->route('feature');
                assert($feature instanceof Feature);

                if ($this->boolean('enabled') && $feature->unavailable() !== null) {
                    $validator->errors()->add('enabled', $feature->unavailable());
                }
            },
        ];
    }
}
