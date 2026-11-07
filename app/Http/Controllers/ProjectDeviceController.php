<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateDevice;
use App\Actions\DeleteDevice;
use App\Actions\IssueDevicePassword;
use App\Http\Requests\StoreProjectDeviceRequest;
use App\Models\Device;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The devices allowed to publish to the project's MQTT topics.
 */
final readonly class ProjectDeviceController
{
    public function store(StoreProjectDeviceRequest $request, Project $project, CreateDevice $create, IssueDevicePassword $issue): RedirectResponse
    {
        Gate::authorize('update', $project);

        $device = $create->handle($project, $request->string('name')->value());

        // Shown once: only the hash is kept.
        Inertia::flash('deviceCredentials', [
            'deviceId' => $device->id,
            'username' => $device->mqttUsername(),
            'password' => $issue->handle($device),
        ]);

        return to_route('projects.show', $project);
    }

    public function destroy(Project $project, Device $device, DeleteDevice $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $action->handle($device);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $device->name])]);

        return to_route('projects.show', $project);
    }
}
