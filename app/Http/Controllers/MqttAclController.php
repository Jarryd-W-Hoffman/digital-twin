<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AuthorizeMqttTopic;
use App\Http\Requests\StoreMqttAclRequest;
use Illuminate\Http\Response;

/**
 * Called by the broker's auth plugin on each subscribe, publish and delivery
 * it hasn't cached: 200 allows it.
 */
final readonly class MqttAclController
{
    public function store(StoreMqttAclRequest $request, AuthorizeMqttTopic $action): Response
    {
        $allowed = $action->handle(
            $request->string('username')->value(),
            $request->string('topic')->value(),
            $request->integer('acc'),
        );

        return response()->noContent($allowed ? 200 : 403);
    }
}
