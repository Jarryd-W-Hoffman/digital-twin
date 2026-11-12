<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AlertSeverity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A gateway's report of every alert open on its twin now.
 */
final class StoreGatewayAlertsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'open' => ['present', 'array', 'list', 'max:500'],
            'open.*' => ['array:rule,asset,severity,message,value'],
            'open.*.rule' => ['required', 'string', 'max:64'],
            'open.*.asset' => ['required', 'string', 'max:64'],
            'open.*.severity' => ['required', Rule::enum(AlertSeverity::class)],
            'open.*.message' => ['required', 'string', 'max:500'],
            'open.*.value' => ['required', 'numeric'],
        ];
    }

    /**
     * @return list<array{rule: string, asset: string, severity: AlertSeverity, message: string, value: float}>
     */
    public function open(): array
    {
        /** @var list<array{rule: string, asset: string, severity: string, message: string, value: int|float|string}> $open */
        $open = $this->validated('open');

        return array_map(fn (array $alert): array => [
            'rule' => $alert['rule'],
            'asset' => $alert['asset'],
            'severity' => AlertSeverity::from($alert['severity']),
            'message' => $alert['message'],
            'value' => (float) $alert['value'],
        ], $open);
    }
}
