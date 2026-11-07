<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\AuthenticateGateway;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A gateway's batch of readings: each for an asset in its twin's latest
 * version, measured in the last week (Unix milliseconds).
 */
final class StoreGatewayReadingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $definition = AuthenticateGateway::twin($this)->latestVersion()->firstOrFail()->definition;
        $assets = array_column(is_array($definition['assets'] ?? null) ? $definition['assets'] : [], 'id');
        $now = now()->getTimestampMs();

        return [
            'readings' => ['required', 'array', 'min:1', 'max:1000'],
            'readings.*.asset' => ['required', 'string', Rule::in($assets)],
            'readings.*.metric' => ['required', 'string', 'regex:/^[a-z][A-Za-z0-9_]{0,63}$/'],
            'readings.*.value' => ['required', 'numeric'],
            'readings.*.at' => ['required', 'integer', 'between:'.($now - 7 * 86_400_000).','.($now + 300_000)],
        ];
    }

    /**
     * @return list<array{asset: string, metric: string, value: float, at: int}>
     */
    public function readings(): array
    {
        /** @var list<array{asset: string, metric: string, value: int|float|string, at: int|string}> $readings */
        $readings = $this->validated('readings');

        return array_map(fn (array $r): array => [
            'asset' => $r['asset'],
            'metric' => $r['metric'],
            'value' => (float) $r['value'],
            'at' => (int) $r['at'],
        ], $readings);
    }
}
