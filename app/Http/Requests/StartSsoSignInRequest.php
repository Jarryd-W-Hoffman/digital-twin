<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SsoConnection;
use App\Models\SsoDomain;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class StartSsoSignInRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * The identity provider for the email's domain.
     *
     * @throws ValidationException when no organisation signs that domain in
     */
    public function connection(): SsoConnection
    {
        $domain = SsoDomain::of($this->string('email')->value());
        $connection = SsoDomain::query()->where('domain', $domain)->first()?->connection;

        throw_unless($connection instanceof SsoConnection, ValidationException::withMessages([
            'email' => __('No organisation signs in :domain through single sign-on. Sign in with your password instead.', ['domain' => $domain]),
        ]));

        return $connection;
    }
}
