<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganisationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Operator => 'Operator',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Editing a twin's definition and its uploaded files.
     */
    public function canEditTwins(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /**
     * Sending commands to a running twin.
     */
    public function canOperate(): bool
    {
        return $this !== self::Viewer;
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
