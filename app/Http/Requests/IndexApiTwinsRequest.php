<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class IndexApiTwinsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project' => ['nullable', 'uuid'],
        ];
    }

    public function project(): ?string
    {
        return $this->filled('project') ? $this->string('project')->value() : null;
    }
}
