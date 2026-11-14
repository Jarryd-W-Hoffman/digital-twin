<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class IndexApiIssuesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'in:open,closed,all'],
        ];
    }

    /**
     * Which issues to list: open ones unless asked otherwise.
     */
    public function state(): string
    {
        return $this->string('state', 'open')->value();
    }
}
