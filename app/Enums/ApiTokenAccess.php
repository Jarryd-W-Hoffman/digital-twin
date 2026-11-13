<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an API token may do in its organisation.
 */
enum ApiTokenAccess: string
{
    case Read = 'read';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read only',
        };
    }
}
