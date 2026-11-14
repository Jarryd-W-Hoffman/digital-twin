<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AlertSeverity;
use App\Enums\AnomalySensitivity;
use App\Models\Twin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveAnomalyMonitorRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $twin = $this->route('twin');
        assert($twin instanceof Twin);
        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $assets = array_values(array_filter(array_map(
            fn (mixed $asset): mixed => is_array($asset) ? ($asset['id'] ?? null) : null,
            is_array($definition['assets'] ?? null) ? $definition['assets'] : [],
        ), is_string(...)));

        return [
            'asset' => [Rule::requiredIf($this->isMethod('POST')), 'string', Rule::in($assets)],
            'metric' => [Rule::requiredIf($this->isMethod('POST')), 'string', 'regex:/^[a-z][A-Za-z0-9_]{0,63}$/'],
            'sensitivity' => ['required', Rule::enum(AnomalySensitivity::class)],
            'severity' => ['required', Rule::enum(AlertSeverity::class)],
            'bands' => ['boolean'],
            'flatline_hours' => ['nullable', 'integer', 'between:1,168'],
        ];
    }

    /**
     * @return array{sensitivity: AnomalySensitivity, severity: AlertSeverity, bands: bool, flatline_hours: int|null}
     */
    public function settings(): array
    {
        return [
            'sensitivity' => AnomalySensitivity::from($this->string('sensitivity')->value()),
            'severity' => AlertSeverity::from($this->string('severity')->value()),
            'bands' => $this->boolean('bands'),
            'flatline_hours' => $this->filled('flatline_hours') ? $this->integer('flatline_hours') : null,
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('bands') && ! $this->filled('flatline_hours')) {
                    $validator->errors()->add('bands', __('Check the band, flatlines, or both.'));
                }
            },
        ];
    }
}
