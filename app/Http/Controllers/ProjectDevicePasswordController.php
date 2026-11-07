<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IssueDevicePassword;
use App\Models\Device;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final readonly class ProjectDevicePasswordController
{
    public function store(Project $project, Device $device, IssueDevicePassword $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        Inertia::flash('deviceCredentials', [
            'deviceId' => $device->id,
            'username' => $device->mqttUsername(),
            'password' => $action->handle($device),
        ]);

        return to_route('projects.show', $project);
    }
}
