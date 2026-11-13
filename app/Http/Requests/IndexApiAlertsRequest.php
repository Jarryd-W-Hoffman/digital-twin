<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class IndexApiAlertsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'open' => ['nullable', 'boolean'],
            'since' => ['nullable', 'date'],
        ];
    }

    public function since(): ?CarbonImmutable
    {
        return $this->filled('since') ? CarbonImmutable::parse($this->string('since')->value()) : null;
    }
}
