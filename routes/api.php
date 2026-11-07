<?php

declare(strict_types=1);

use App\Http\Controllers\GatewayDefinitionController;
use App\Http\Controllers\GatewayReadingController;
use App\Http\Middleware\AuthenticateGateway;
use Illuminate\Support\Facades\Route;

// Gateways authenticate with their twin's token as a bearer token.
Route::middleware(AuthenticateGateway::class)->prefix('gateway')->name('gateway.')->group(function (): void {
    Route::get('definition', [GatewayDefinitionController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('definition.show');
    Route::post('readings', [GatewayReadingController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('readings.store');
});
