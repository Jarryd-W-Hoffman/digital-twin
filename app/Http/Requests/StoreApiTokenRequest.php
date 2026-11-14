<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ApiTokenAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreApiTokenRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'access' => ['required', Rule::enum(ApiTokenAccess::class)],
            'expires_in_days' => ['nullable', 'integer', 'in:30,90,365'],
        ];
    }

    public function access(): ApiTokenAccess
    {
        return $this->enum('access', ApiTokenAccess::class) ?? ApiTokenAccess::Read;
    }

    /**
     * When the token stops working, or null for never.
     */
    public function expiresAt(): ?CarbonImmutable
    {
        return $this->filled('expires_in_days') ? CarbonImmutable::now()->addDays($this->integer('expires_in_days')) : null;
    }
}
