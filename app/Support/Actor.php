<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ApiToken;
use App\Models\User;

/**
 * Who did something: a person, or another system through an API token. A
 * person is kept by id; a token by its name, which outlives revoking it.
 */
final readonly class Actor
{
    /**
     * The columns to store for whoever did it.
     *
     * @return array{0: string|null, 1: string|null} The user's id and the token's name.
     */
    public static function columns(User|ApiToken $by): array
    {
        return $by instanceof User ? [$by->id, null] : [null, $by->name];
    }

    public static function name(User|ApiToken $by): string
    {
        return $by instanceof User ? $by->name : $by->signature();
    }

    /**
     * How a token's stored name is shown, or null if none was stored.
     */
    public static function apiName(?string $name): ?string
    {
        return $name === null ? null : (string) __(':name (API)', ['name' => $name]);
    }
}
