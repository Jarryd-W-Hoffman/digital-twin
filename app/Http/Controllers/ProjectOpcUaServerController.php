<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateOpcUaServer;
use App\Actions\DeleteOpcUaServer;
use App\Http\Requests\StoreProjectOpcUaServerRequest;
use App\Models\OpcUaServer;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The OPC UA servers the project's gateways read from.
 */
final readonly class ProjectOpcUaServerController
{
    public function store(StoreProjectOpcUaServerRequest $request, Project $project, CreateOpcUaServer $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $username = $request->string('username')->trim()->value();
        $server = $action->handle(
            $project,
            $request->string('name')->value(),
            $request->string('endpoint')->value(),
            $request->securityMode(),
            $request->securityPolicy(),
            $username === '' ? null : $username,
            $request->string('password')->value(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $server->name])]);

        return to_route('projects.show', $project);
    }

    public function destroy(Project $project, OpcUaServer $opcUaServer, DeleteOpcUaServer $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $action->handle($opcUaServer);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $opcUaServer->name])]);

        return to_route('projects.show', $project);
    }
}
