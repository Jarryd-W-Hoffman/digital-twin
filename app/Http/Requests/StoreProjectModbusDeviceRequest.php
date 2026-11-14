<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProjectModbusDeviceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        assert($project instanceof Project);

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('modbus_devices')->where('project_id', $project->id)],
            'host' => ['required', 'string', 'max:255', 'regex:/^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$|^[0-9A-Fa-f:]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'unit_id' => ['required', 'integer', 'between:0,255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'host.regex' => __('The host must be a name or an IP address, such as meter.local or 192.168.1.20, without a port.'),
        ];
    }
}
