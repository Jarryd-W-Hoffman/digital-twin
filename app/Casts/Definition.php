<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\DefinitionFormat;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A twin definition stored as JSON, read and written in the current format:
 * versions saved in an older one are migrated as they're read, and anything
 * saved is migrated first.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class Definition implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var array<string, mixed> $definition */
        $definition = DefinitionFormat::migrate(json_decode(is_string($value) ? $value : '{}', true));

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode(DefinitionFormat::migrate($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
