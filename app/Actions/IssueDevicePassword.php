<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Device;
use Illuminate\Support\Str;

/**
 * Gives a device a new broker password, replacing any earlier one. Only its
 * hash is stored, so the password is returned once and cannot be shown again.
 */
final readonly class IssueDevicePassword
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Device $device): string
    {
        $password = 'mdv_'.Str::random(40);

        $device->update(['password_hash' => hash('sha256', $password), 'last_seen_at' => null]);

        $this->audit->handle(AuditAction::DevicePasswordReset, $device->project, $device->name);

        return $password;
    }
}
