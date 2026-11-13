<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Twin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Who an issue is given to, a member of the twin's organisation, and when
 * it's due; either may be left empty.
 */
final class UpdateIssueAssignmentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $twin = $this->route('twin');
        assert($twin instanceof Twin);

        return [
            'assignee' => ['nullable', 'uuid', Rule::exists('memberships', 'user_id')->where('organisation_id', $twin->organisation_id)],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
        ];
    }

    public function assignee(): ?User
    {
        return $this->filled('assignee') ? User::query()->findOrFail($this->string('assignee')->value()) : null;
    }

    public function dueOn(): ?CarbonImmutable
    {
        return $this->filled('due_on') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->string('due_on')->value()) ?: null : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['due_on' => __('due date')];
    }
}
