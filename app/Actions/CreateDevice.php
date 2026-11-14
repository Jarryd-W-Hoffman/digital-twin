<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Device;
use App\Models\Project;

final readonly class CreateDevice
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Project $project, string $name): Device
    {
        $device = $project->devices()->create(['name' => $name]);

        $this->audit->handle(AuditAction::DeviceAdded, $project, $name);

        return $device;
    }
}
