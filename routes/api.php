<?php

declare(strict_types=1);

use App\Http\Controllers\ApiAlertAcknowledgementController;
use App\Http\Controllers\ApiAuditController;
use App\Http\Controllers\ApiIssueClosureController;
use App\Http\Controllers\ApiIssueCommentController;
use App\Http\Controllers\ApiIssuePhotoController;
use App\Http\Controllers\ApiProjectController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ApiTwinAlertController;
use App\Http\Controllers\ApiTwinController;
use App\Http\Controllers\ApiTwinIssueController;
use App\Http\Controllers\ApiTwinReadingController;
use App\Http\Controllers\GatewayAlertController;
use App\Http\Controllers\GatewayDefinitionController;
use App\Http\Controllers\GatewayOpcUaWriteController;
use App\Http\Controllers\GatewayReadingController;
use App\Http\Controllers\MqttAclController;
use App\Http\Controllers\MqttUserController;
use App\Http\Controllers\ScimServiceProviderConfigController;
use App\Http\Controllers\ScimUserController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateBroker;
use App\Http\Middleware\AuthenticateGateway;
use App\Http\Middleware\AuthenticateScimToken;
use App\Http\Middleware\EnsureApiTokenWrites;
use Illuminate\Support\Facades\Route;

// Gateways authenticate with their twin's token as a bearer token.
Route::middleware(AuthenticateGateway::class)->prefix('gateway')->name('gateway.')->group(function (): void {
    Route::get('definition', [GatewayDefinitionController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('definition.show');
    Route::post('readings', [GatewayReadingController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('readings.store');
    Route::post('opcua-writes', [GatewayOpcUaWriteController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('opcua-writes.store');
    Route::post('alerts', [GatewayAlertController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('alerts.store');
});

// The MQTT broker's auth plugin asks who may connect, publish and subscribe.
Route::middleware(AuthenticateBroker::class)->prefix('mqtt/{secret}')->name('mqtt.')->group(function (): void {
    Route::post('user', [MqttUserController::class, 'store'])
        ->middleware('throttle:mqtt-logins')
        ->name('user.store');
    Route::post('acl', [MqttAclController::class, 'store'])->name('acl.store');
});

// Other systems read an organisation's data with one of its API tokens, and
// those that may write add readings, raise and comment on issues and
// acknowledge alerts.
Route::middleware([AuthenticateApiToken::class, 'throttle:api'])->prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('token', [ApiTokenController::class, 'show'])->name('token.show');
    Route::get('audit', [ApiAuditController::class, 'index'])->name('audit.index');
    Route::get('projects', [ApiProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/{project}', [ApiProjectController::class, 'show'])->name('projects.show');
    Route::get('twins', [ApiTwinController::class, 'index'])->name('twins.index');
    Route::get('twins/{twin}', [ApiTwinController::class, 'show'])->name('twins.show');
    Route::get('twins/{twin}/readings', [ApiTwinReadingController::class, 'index'])->name('twins.readings.index');
    Route::post('twins/{twin}/readings', [ApiTwinReadingController::class, 'store'])->middleware(EnsureApiTokenWrites::class)->name('twins.readings.store');
    Route::get('twins/{twin}/alerts', [ApiTwinAlertController::class, 'index'])->name('twins.alerts.index');
    Route::get('twins/{twin}/issues', [ApiTwinIssueController::class, 'index'])->name('twins.issues.index');
    Route::post('twins/{twin}/issues', [ApiTwinIssueController::class, 'store'])->middleware(EnsureApiTokenWrites::class)->name('twins.issues.store');
    Route::scopeBindings()->group(function (): void {
        Route::get('twins/{twin}/issues/{issue}', [ApiTwinIssueController::class, 'show'])->name('twins.issues.show');
        Route::get('twins/{twin}/issues/{issue}/comments/{comment}/photo', [ApiIssuePhotoController::class, 'show'])->name('twins.issues.comments.photo');
        Route::post('twins/{twin}/issues/{issue}/comments', [ApiIssueCommentController::class, 'store'])->middleware(EnsureApiTokenWrites::class)->name('twins.issues.comments.store');
        Route::post('twins/{twin}/issues/{issue}/closure', [ApiIssueClosureController::class, 'store'])->middleware(EnsureApiTokenWrites::class)->name('twins.issues.closure.store');
        Route::delete('twins/{twin}/issues/{issue}/closure', [ApiIssueClosureController::class, 'destroy'])->middleware(EnsureApiTokenWrites::class)->name('twins.issues.closure.destroy');
        Route::post('twins/{twin}/alerts/{alert}/acknowledgement', [ApiAlertAcknowledgementController::class, 'store'])->middleware(EnsureApiTokenWrites::class)->name('twins.alerts.acknowledgement.store');
    });
});

// An organisation's identity provider provisions its people (SCIM 2.0) with
// its single sign-on connection's provisioning token.
Route::middleware([AuthenticateScimToken::class, 'throttle:scim'])->prefix('scim/v2')->name('scim.')->group(function (): void {
    Route::get('ServiceProviderConfig', [ScimServiceProviderConfigController::class, 'show'])->name('service-provider-config');
    Route::get('Users', [ScimUserController::class, 'index'])->name('users.index');
    Route::post('Users', [ScimUserController::class, 'store'])->name('users.store');
    Route::get('Users/{id}', [ScimUserController::class, 'show'])->name('users.show');
    Route::put('Users/{id}', [ScimUserController::class, 'update'])->name('users.update');
    Route::patch('Users/{id}', [ScimUserController::class, 'update'])->name('users.patch');
    Route::delete('Users/{id}', [ScimUserController::class, 'destroy'])->name('users.destroy');
});
