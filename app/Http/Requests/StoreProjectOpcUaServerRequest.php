<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OpcUaSecurityMode;
use App\Enums\OpcUaSecurityPolicy;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreProjectOpcUaServerRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        assert($project instanceof Project);

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('opc_ua_servers')->where('project_id', $project->id)],
            'endpoint' => ['required', 'string', 'max:255', 'regex:#^opc\.tcp://[^\s/:]+(:\d{1,5})?(/\S*)?$#'],
            'security_mode' => ['required', Rule::enum(OpcUaSecurityMode::class)],
            'security_policy' => ['required', Rule::enum(OpcUaSecurityPolicy::class)],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255', 'required_with:username'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endpoint.regex' => __('The endpoint must be an opc.tcp:// address, such as opc.tcp://plc.local:4840.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $mode = OpcUaSecurityMode::tryFrom($this->string('security_mode')->value());
                $policy = OpcUaSecurityPolicy::tryFrom($this->string('security_policy')->value());

                if ($mode === null || $policy === null) {
                    return;
                }

                if (($mode === OpcUaSecurityMode::None) !== ($policy === OpcUaSecurityPolicy::None)) {
                    $validator->errors()->add('security_policy', $mode === OpcUaSecurityMode::None
                        ? __('An unsecured connection uses no security policy.')
                        : __('A signed connection needs a security policy.'));
                }
            },
        ];
    }

    public function securityMode(): OpcUaSecurityMode
    {
        return OpcUaSecurityMode::from($this->string('security_mode')->value());
    }

    public function securityPolicy(): OpcUaSecurityPolicy
    {
        return OpcUaSecurityPolicy::from($this->string('security_policy')->value());
    }
}
