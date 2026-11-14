<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreShareLinkRequest extends FormRequest
{
    /**
     * How long a link can last, in days.
     */
    public const array LIFETIMES = [1, 7, 30, 90];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'expires_in_days' => ['required', 'integer', Rule::in(self::LIFETIMES)],
            'live' => ['boolean'],
        ];
    }

    public function expiresAt(): CarbonInterface
    {
        return now()->addDays($this->integer('expires_in_days'));
    }
}
