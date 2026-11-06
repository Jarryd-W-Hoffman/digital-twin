<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganisationRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMemberRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['required', Rule::enum(OrganisationRole::class)],
        ];
    }

    public function role(): OrganisationRole
    {
        return $this->enum('role', OrganisationRole::class) ?? OrganisationRole::Viewer;
    }
}
