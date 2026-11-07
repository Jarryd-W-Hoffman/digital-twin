<?php

declare(strict_types=1);

use App\Http\Controllers\GatewayDefinitionController;
use App\Http\Controllers\GatewayReadingController;
use App\Http\Controllers\MqttAclController;
use App\Http\Controllers\MqttUserController;
use App\Http\Middleware\AuthenticateBroker;
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

// The MQTT broker's auth plugin asks who may connect, publish and subscribe.
Route::middleware(AuthenticateBroker::class)->prefix('mqtt/{secret}')->name('mqtt.')->group(function (): void {
    Route::post('user', [MqttUserController::class, 'store'])
        ->middleware('throttle:mqtt-logins')
        ->name('user.store');
    Route::post('acl', [MqttAclController::class, 'store'])->name('acl.store');
});
