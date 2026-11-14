<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ScimError;
use Illuminate\Support\Str;

/**
 * Reads what Meridian keeps from a SCIM User (RFC 7643): the userName the
 * provider knows them by, its externalId, a display name, the work email and
 * whether they're active. Everything else a provider sends is ignored.
 */
final readonly class ScimAttributes
{
    public function __construct(
        public string $userName,
        public ?string $externalId,
        public ?string $name,
        public ?string $email,
        public bool $active,
    ) {}

    /**
     * @param  array<mixed>  $resource
     */
    public static function from(array $resource): self
    {
        $userName = $resource['userName'] ?? null;
        throw_unless(is_string($userName) && mb_trim($userName) !== '', ScimError::class, 400, __('A userName is required.'), 'invalidValue');

        $externalId = $resource['externalId'] ?? null;

        return new self(
            userName: mb_strtolower(mb_trim($userName)),
            externalId: is_string($externalId) ? $externalId : null,
            name: self::name($resource),
            email: self::email($resource),
            active: self::boolean($resource['active'] ?? true),
        );
    }

    /**
     * Entra sends booleans in PATCH as the strings "True" and "False".
     */
    public static function boolean(mixed $value): bool
    {
        return is_string($value) ? Str::lower($value) === 'true' : (bool) $value;
    }

    /**
     * The address to find or make their account with: the primary email, the
     * first one, or the userName when it's an address.
     */
    public function address(): string
    {
        $address = mb_strtolower($this->email ?? $this->userName);
        throw_unless(filter_var($address, FILTER_VALIDATE_EMAIL) !== false, ScimError::class, 400, __('An email address is required, in emails or as the userName.'), 'invalidValue');

        return $address;
    }

    /**
     * @param  array<mixed>  $resource
     */
    private static function name(array $resource): ?string
    {
        $name = is_array($resource['name'] ?? null) ? $resource['name'] : [];
        $formatted = $name['formatted'] ?? null;
        $parts = mb_trim(implode(' ', array_filter([$name['givenName'] ?? null, $name['familyName'] ?? null], is_string(...))));
        $display = $resource['displayName'] ?? null;

        return match (true) {
            is_string($formatted) && mb_trim($formatted) !== '' => mb_trim($formatted),
            $parts !== '' => $parts,
            is_string($display) && mb_trim($display) !== '' => mb_trim($display),
            default => null,
        };
    }

    /**
     * @param  array<mixed>  $resource
     */
    private static function email(array $resource): ?string
    {
        $emails = array_values(array_filter(is_array($resource['emails'] ?? null) ? $resource['emails'] : [], fn (mixed $email): bool => is_array($email) && is_string($email['value'] ?? null)));
        $primary = array_values(array_filter($emails, fn (array $email): bool => self::boolean($email['primary'] ?? false)));
        $chosen = $primary[0] ?? $emails[0] ?? null;

        return is_array($chosen) ? $chosen['value'] : null;
    }
}
