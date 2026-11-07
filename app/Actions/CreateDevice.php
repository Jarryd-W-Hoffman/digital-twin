<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Device;
use App\Models\Project;

final readonly class CreateDevice
{
    public function handle(Project $project, string $name): Device
    {
        return $project->devices()->create(['name' => $name]);
    }
}
