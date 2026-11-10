<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A gateway's report of one setpoint written to an OPC UA node, as Unix
 * milliseconds within the last day.
 */
final class StoreGatewayOpcUaWriteRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $now = now()->getTimestampMs();

        return [
            'server' => ['required', 'string', 'max:100'],
            'node' => ['required', 'string', 'max:255'],
            'asset' => ['required', 'string', 'max:64'],
            'metric' => ['required', 'string', 'regex:/^[a-z][A-Za-z0-9_]{0,63}$/'],
            'requested' => ['required', 'numeric'],
            'setpoint' => ['required', 'numeric'],
            'value' => ['required', 'numeric'],
            'user' => ['present', 'nullable', 'string', 'max:64'],
            'succeeded' => ['required', 'boolean'],
            'error' => ['present', 'nullable', 'string'],
            'at' => ['required', 'integer', 'between:'.($now - 86_400_000).','.($now + 300_000)],
        ];
    }

    /**
     * @return array{server: string, node: string, asset: string, metric: string, requested: float, setpoint: float, value: float, user: string|null, succeeded: bool, error: string|null, at: int}
     */
    public function write(): array
    {
        $error = $this->input('error');
        $user = $this->input('user');

        return [
            'server' => $this->string('server')->value(),
            'node' => $this->string('node')->value(),
            'asset' => $this->string('asset')->value(),
            'metric' => $this->string('metric')->value(),
            'requested' => $this->float('requested'),
            'setpoint' => $this->float('setpoint'),
            'value' => $this->float('value'),
            'user' => is_string($user) ? $user : null,
            'succeeded' => $this->boolean('succeeded'),
            'error' => is_string($error) ? mb_substr($error, 0, 500) : null,
            'at' => $this->integer('at'),
        ];
    }
}
