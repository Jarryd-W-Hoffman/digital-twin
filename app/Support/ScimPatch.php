<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ScimError;
use Illuminate\Support\Str;

/**
 * Applies a SCIM PATCH (RFC 7644, section 3.5.2) to a User's current
 * representation, for the attributes Meridian follows. Operations on anything
 * else, emails included (an account's address is its own), are ignored rather
 * than refused, since providers send more than Meridian stores.
 */
final readonly class ScimPatch
{
    /**
     * @param  array<mixed>  $resource
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    public static function apply(array $resource, array $body): array
    {
        $operations = $body['Operations'] ?? null;
        throw_unless(is_array($operations), ScimError::class, 400, __('A PATCH needs Operations.'), 'invalidSyntax');

        foreach ($operations as $operation) {
            throw_unless(is_array($operation) && is_string($operation['op'] ?? null), ScimError::class, 400, __('Each operation needs an op.'), 'invalidSyntax');

            $op = Str::lower($operation['op']);
            throw_unless(in_array($op, ['add', 'replace', 'remove'], true), ScimError::class, 400, __('Unknown op: :op.', ['op' => $operation['op']]), 'invalidSyntax');

            $path = $operation['path'] ?? null;
            $value = $op === 'remove' ? null : ($operation['value'] ?? null);

            if (! is_string($path)) {
                throw_unless(is_array($value), ScimError::class, 400, __('An operation without a path needs an object value.'), 'invalidValue');

                foreach ($value as $key => $item) {
                    $resource = self::set($resource, (string) $key, $item);
                }

                continue;
            }

            $resource = self::set($resource, $path, $value);
        }

        return $resource;
    }

    /**
     * @param  array<mixed>  $resource
     * @return array<mixed>
     */
    private static function set(array $resource, string $path, mixed $value): array
    {
        $key = Str::lower($path);

        return match (true) {
            $key === 'active' => [...$resource, 'active' => ScimAttributes::boolean($value ?? false)],
            $key === 'username' => [...$resource, 'userName' => $value],
            $key === 'externalid' => [...$resource, 'externalId' => $value],
            $key === 'displayname' => [...$resource, 'displayName' => $value],
            $key === 'name' => [...$resource, 'name' => is_array($value) ? $value : []],
            str_starts_with($key, 'name.') => [...$resource, 'name' => self::name($resource, Str::after($path, '.'), $value)],
            default => $resource,
        };
    }

    /**
     * One part of the name. Changing the given or family name drops the
     * formatted one, which would otherwise win.
     *
     * @param  array<mixed>  $resource
     * @return array<mixed>
     */
    private static function name(array $resource, string $part, mixed $value): array
    {
        $name = is_array($resource['name'] ?? null) ? $resource['name'] : [];
        if ($part !== 'formatted') {
            unset($name['formatted']);
        }

        return [...$name, $part => $value];
    }
}
