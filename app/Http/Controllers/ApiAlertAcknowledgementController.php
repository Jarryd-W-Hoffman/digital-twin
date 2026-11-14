<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcknowledgeAlert;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Twin;
use Illuminate\Http\Request;

/**
 * Another system acknowledging an alert, such as when it has opened a work
 * order for it. The first acknowledgement is kept, as in the alert log.
 */
final readonly class ApiAlertAcknowledgementController
{
    public function store(Request $request, Twin $twin, Alert $alert, AcknowledgeAlert $action): AlertResource
    {
        $token = AuthenticateApiToken::token($request);
        abort_unless($token->reaches($twin), 404);

        $action->handle($alert, $token);

        return new AlertResource($alert->refresh()->load('acknowledger:id,name'));
    }
}
