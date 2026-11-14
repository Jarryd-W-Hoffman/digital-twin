<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\Twin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Readings another system sends with a write token: each for an asset in the
 * twin's latest version, taken within the time readings are kept (Unix
 * milliseconds).
 */
final class StoreApiReadingsRequest extends FormRequest
{
    /**
     * A twin outside the token's organisation isn't there, before its assets
     * are checked.
     */
    public function authorize(): bool
    {
        /** @var Twin $twin */
        $twin = $this->route('twin');
        abort_unless(AuthenticateApiToken::token($this)->reaches($twin), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Twin $twin */
        $twin = $this->route('twin');
        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $assets = array_column(is_array($definition['assets'] ?? null) ? $definition['assets'] : [], 'id');
        $oldest = now()->subDays(config()->integer('meridian.readings.retention_days'))->getTimestampMs();

        return [
            'readings' => ['required', 'array', 'min:1', 'max:1000'],
            'readings.*.asset' => ['required', 'string', Rule::in($assets)],
            'readings.*.metric' => ['required', 'string', 'regex:/^[a-z][A-Za-z0-9_]{0,63}$/'],
            'readings.*.value' => ['required', 'numeric'],
            'readings.*.at' => ['required', 'integer', 'between:'.$oldest.','.(now()->getTimestampMs() + 300_000)],
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
