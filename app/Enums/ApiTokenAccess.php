<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an API token may do in its organisation.
 */
enum ApiTokenAccess: string
{
    case Read = 'read';
    case Write = 'write';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read only',
            self::Write => 'Read and write',
        };
    }

    /**
     * Raising, commenting on and closing issues, and acknowledging alerts.
     */
    public function canWrite(): bool
    {
        return $this === self::Write;
    }
}
