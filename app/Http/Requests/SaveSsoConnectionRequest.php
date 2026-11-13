<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganisationRole;
use App\Models\SsoConnection;
use App\Models\SsoDomain;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveSsoConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('manageSso', $user->organisation());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'issuer' => ['required', 'string', 'url', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => [$this->existing() instanceof SsoConnection ? 'nullable' : 'required', 'string', 'max:2000'],
            'domains' => ['required', 'string', 'max:1000'],
            'auto_join' => ['boolean'],
            'default_role' => ['required', Rule::enum(OrganisationRole::class)->only([OrganisationRole::Viewer, OrganisationRole::Operator])],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('domains')) {
                    return;
                }

                foreach ($this->domains() as $domain) {
                    if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
                        $validator->errors()->add('domains', __(":domain isn't a domain, like example.com.", ['domain' => $domain]));

                        return;
                    }
                }

                $existing = $this->existing();
                $taken = SsoDomain::query()
                    ->whereIn('domain', $this->domains())
                    ->when($existing instanceof SsoConnection, fn (Builder $query): Builder => $query->where('sso_connection_id', '!=', $existing?->id))
                    ->value('domain');

                if (is_string($taken)) {
                    $validator->errors()->add('domains', __('Another organisation already signs in :domain.', ['domain' => $taken]));
                }
            },
        ];
    }

    /**
     * The domains, one per comma, space or line.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/[\s,]+/', Str::lower($this->string('domains')->value())) ?: [],
            fn (string $domain): bool => $domain !== '',
        )));
    }

    /**
     * @return array{issuer: string, client_id: string, client_secret: string|null, domains: list<string>, auto_join: bool, default_role: OrganisationRole}
     */
    public function settings(): array
    {
        return [
            'issuer' => mb_trim($this->string('issuer')->value()),
            'client_id' => mb_trim($this->string('client_id')->value()),
            'client_secret' => $this->filled('client_secret') ? $this->string('client_secret')->value() : null,
            'domains' => $this->domains(),
            'auto_join' => $this->boolean('auto_join'),
            'default_role' => $this->enum('default_role', OrganisationRole::class) ?? OrganisationRole::Viewer,
        ];
    }

    private function existing(): ?SsoConnection
    {
        $user = $this->user();

        return $user instanceof User ? $user->organisation()->ssoConnection : null;
    }
}
