<?php

declare(strict_types=1);

use App\Http\Controllers\GatewayDefinitionController;
use Illuminate\Support\Facades\Route;

// Gateways authenticate with their twin's token as a bearer token.
Route::get('gateway/definition', [GatewayDefinitionController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('gateway.definition.show');
