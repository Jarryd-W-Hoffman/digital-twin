<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateModbusDevice;
use App\Actions\DeleteModbusDevice;
use App\Http\Requests\StoreProjectModbusDeviceRequest;
use App\Models\ModbusDevice;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The Modbus TCP devices the project's gateways poll.
 */
final readonly class ProjectModbusDeviceController
{
    public function store(StoreProjectModbusDeviceRequest $request, Project $project, CreateModbusDevice $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $device = $action->handle(
            $project,
            $request->string('name')->value(),
            $request->string('host')->value(),
            $request->integer('port'),
            $request->integer('unit_id'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $device->name])]);

        return to_route('projects.show', $project);
    }

    public function destroy(Project $project, ModbusDevice $modbusDevice, DeleteModbusDevice $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $action->handle($modbusDevice);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $modbusDevice->name])]);

        return to_route('projects.show', $project);
    }
}
