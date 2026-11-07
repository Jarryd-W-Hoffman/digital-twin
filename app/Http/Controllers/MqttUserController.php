<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AuthenticateMqttClient;
use App\Http\Requests\StoreMqttUserRequest;
use Illuminate\Http\Response;

/**
 * Called by the broker's auth plugin when a client connects: 200 lets it in.
 */
final readonly class MqttUserController
{
    public function store(StoreMqttUserRequest $request, AuthenticateMqttClient $action): Response
    {
        $allowed = $action->handle($request->string('username')->value(), $request->string('password')->value());

        return response()->noContent($allowed ? 200 : 403);
    }
}
