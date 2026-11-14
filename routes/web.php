<?php

declare(strict_types=1);

use App\Http\Controllers\OrganisationApiTokenController;
use App\Http\Controllers\OrganisationAuditController;
use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\OrganisationMemberController;
use App\Http\Controllers\OrganisationScimTokenController;
use App\Http\Controllers\OrganisationSsoController;
use App\Http\Controllers\OrganisationTypeController;
use App\Http\Controllers\ProjectAssetController;
use App\Http\Controllers\ProjectAssetConversionController;
use App\Http\Controllers\ProjectAssetFileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDeviceController;
use App\Http\Controllers\ProjectDevicePasswordController;
use App\Http\Controllers\ProjectNotificationChannelController;
use App\Http\Controllers\ProjectNotificationChannelReportController;
use App\Http\Controllers\ProjectNotificationChannelTestController;
use App\Http\Controllers\ProjectOpcUaServerController;
use App\Http\Controllers\ProjectUploadCompletionController;
use App\Http\Controllers\ProjectUploadController;
use App\Http\Controllers\ProjectUploadPartController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SsoCallbackController;
use App\Http\Controllers\SsoRequiredController;
use App\Http\Controllers\SsoSessionController;
use App\Http\Controllers\TwinAlertAcknowledgementController;
use App\Http\Controllers\TwinAlertController;
use App\Http\Controllers\TwinAssetController;
use App\Http\Controllers\TwinController;
use App\Http\Controllers\TwinGatewayTokenController;
use App\Http\Controllers\TwinIssueAssignmentController;
use App\Http\Controllers\TwinIssueClosureController;
use App\Http\Controllers\TwinIssueCommentController;
use App\Http\Controllers\TwinIssueController;
use App\Http\Controllers\TwinIssuePhotoController;
use App\Http\Controllers\TwinLayerController;
use App\Http\Controllers\TwinOpcUaWritesController;
use App\Http\Controllers\TwinOpeningViewController;
use App\Http\Controllers\TwinReadingController;
use App\Http\Controllers\TwinTypeController;
use App\Http\Controllers\TwinVersionController;
use App\Http\Controllers\TwinVersionRestorationController;
use App\Http\Controllers\TwinViewController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserEmailResetNotificationController;
use App\Http\Controllers\UserEmailVerificationController;
use App\Http\Controllers\UserEmailVerificationNotificationController;
use App\Http\Controllers\UserPasskeyController;
use App\Http\Controllers\UserPasswordController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UserSsoIdentityController;
use App\Http\Controllers\UserTwoFactorAuthenticationController;
use App\Http\Middleware\EnsureCurrentOrganisation;
use App\Http\Middleware\EnsureSsoSignIn;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome'))->name('home');

// The sample twins run in the browser without an account.
Route::get('demo', fn () => Inertia::render('demo'))->name('demo');

Route::middleware(['auth', 'verified', EnsureCurrentOrganisation::class, EnsureSsoSignIn::class])->group(function (): void {
    Route::get('dashboard', fn () => to_route('projects.index'))->name('dashboard');

    // Projects and their libraries...
    Route::resource('projects', ProjectController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('projects/{project}/assets', [ProjectAssetController::class, 'store'])->name('projects.assets.store');
    Route::delete('projects/{project}/assets/{asset}', [ProjectAssetController::class, 'destroy'])->scopeBindings()->name('projects.assets.destroy');
    Route::post('projects/{project}/assets/{asset}/conversion', [ProjectAssetConversionController::class, 'store'])->scopeBindings()->name('projects.assets.conversion.store');
    Route::post('projects/{project}/devices', [ProjectDeviceController::class, 'store'])->name('projects.devices.store');
    Route::delete('projects/{project}/devices/{device}', [ProjectDeviceController::class, 'destroy'])->scopeBindings()->name('projects.devices.destroy');
    Route::post('projects/{project}/devices/{device}/password', [ProjectDevicePasswordController::class, 'store'])->scopeBindings()->name('projects.devices.password.store');
    Route::post('projects/{project}/opcua-servers', [ProjectOpcUaServerController::class, 'store'])->name('projects.opcua-servers.store');
    Route::delete('projects/{project}/opcua-servers/{opcUaServer}', [ProjectOpcUaServerController::class, 'destroy'])->scopeBindings()->name('projects.opcua-servers.destroy');
    Route::post('projects/{project}/notification-channels', [ProjectNotificationChannelController::class, 'store'])->name('projects.notification-channels.store');
    Route::delete('projects/{project}/notification-channels/{notificationChannel}', [ProjectNotificationChannelController::class, 'destroy'])->scopeBindings()->name('projects.notification-channels.destroy');
    Route::post('projects/{project}/notification-channels/{notificationChannel}/test', [ProjectNotificationChannelTestController::class, 'store'])->scopeBindings()->middleware('throttle:10,1')->name('projects.notification-channels.test');
    Route::post('projects/{project}/notification-channels/{notificationChannel}/report', [ProjectNotificationChannelReportController::class, 'store'])->scopeBindings()->middleware('throttle:10,1')->name('projects.notification-channels.report');
    Route::post('projects/{project}/uploads', [ProjectUploadController::class, 'store'])->name('projects.uploads.store');
    Route::post('projects/{project}/uploads/{asset}/parts', [ProjectUploadPartController::class, 'store'])->scopeBindings()->name('projects.uploads.parts');
    Route::post('projects/{project}/uploads/{asset}/complete', [ProjectUploadCompletionController::class, 'store'])->scopeBindings()->name('projects.uploads.complete');
    Route::delete('projects/{project}/uploads/{asset}', [ProjectUploadController::class, 'destroy'])->scopeBindings()->name('projects.uploads.destroy');
    Route::get('twins/{twin}/readings', [TwinReadingController::class, 'index'])->name('twins.readings.index');
    Route::get('assets/{asset}/files/{path}', [ProjectAssetFileController::class, 'show'])
        ->where('path', '.*')
        ->name('assets.files.show');

    // Twins...
    Route::resource('twins', TwinController::class)->except(['edit']);
    Route::post('twins/{twin}/versions', [TwinVersionController::class, 'store'])->name('twins.versions.store');
    Route::get('twins/{twin}/versions/{version}', [TwinVersionController::class, 'show'])->scopeBindings()->name('twins.versions.show');
    Route::post('twins/{twin}/versions/{version}/restore', [TwinVersionRestorationController::class, 'store'])->scopeBindings()->name('twins.versions.restore');
    Route::post('twins/{twin}/assets', [TwinAssetController::class, 'store'])->name('twins.assets.store');
    Route::post('twins/{twin}/layers', [TwinLayerController::class, 'store'])->name('twins.layers.store');
    Route::delete('twins/{twin}/layers/{asset}', [TwinLayerController::class, 'destroy'])->name('twins.layers.destroy');
    Route::post('twins/{twin}/types', [TwinTypeController::class, 'store'])->name('twins.types.store');
    Route::put('twins/{twin}/types/{type}/library', [TwinTypeController::class, 'update'])->name('twins.types.library.update');
    Route::post('twins/{twin}/gateway-token', [TwinGatewayTokenController::class, 'store'])->name('twins.gateway-token.store');
    Route::delete('twins/{twin}/gateway-token', [TwinGatewayTokenController::class, 'destroy'])->name('twins.gateway-token.destroy');
    Route::put('twins/{twin}/opcua-writes', [TwinOpcUaWritesController::class, 'update'])->name('twins.opcua-writes.update');
    Route::get('twins/{twin}/alerts', [TwinAlertController::class, 'index'])->name('twins.alerts.index');
    Route::post('twins/{twin}/alerts/{alert}/acknowledgement', [TwinAlertAcknowledgementController::class, 'store'])->scopeBindings()->name('twins.alerts.acknowledgement.store');
    Route::post('twins/{twin}/issues', [TwinIssueController::class, 'store'])->middleware('throttle:30,1')->name('twins.issues.store');
    Route::delete('twins/{twin}/issues/{issue}', [TwinIssueController::class, 'destroy'])->scopeBindings()->name('twins.issues.destroy');
    Route::patch('twins/{twin}/issues/{issue}/assignment', [TwinIssueAssignmentController::class, 'update'])->scopeBindings()->name('twins.issues.assignment.update');
    Route::post('twins/{twin}/issues/{issue}/closure', [TwinIssueClosureController::class, 'store'])->scopeBindings()->name('twins.issues.closure.store');
    Route::delete('twins/{twin}/issues/{issue}/closure', [TwinIssueClosureController::class, 'destroy'])->scopeBindings()->name('twins.issues.closure.destroy');
    Route::post('twins/{twin}/issues/{issue}/comments', [TwinIssueCommentController::class, 'store'])->scopeBindings()->middleware('throttle:60,1')->name('twins.issues.comments.store');
    Route::delete('twins/{twin}/issues/{issue}/comments/{comment}', [TwinIssueCommentController::class, 'destroy'])->scopeBindings()->name('twins.issues.comments.destroy');
    Route::get('twins/{twin}/issues/{issue}/comments/{comment}/photo', [TwinIssuePhotoController::class, 'show'])->scopeBindings()->name('twins.issues.comments.photo');
    Route::post('twins/{twin}/views', [TwinViewController::class, 'store'])->middleware('throttle:30,1')->name('twins.views.store');
    Route::patch('twins/{twin}/views/{savedView}', [TwinViewController::class, 'update'])->scopeBindings()->name('twins.views.update');
    Route::delete('twins/{twin}/views/{savedView}', [TwinViewController::class, 'destroy'])->scopeBindings()->name('twins.views.destroy');
    Route::post('twins/{twin}/views/{savedView}/opening', [TwinOpeningViewController::class, 'store'])->scopeBindings()->name('twins.views.opening.store');
    Route::delete('twins/{twin}/opening-view', [TwinOpeningViewController::class, 'destroy'])->name('twins.opening-view.destroy');

    // Organisations...
    Route::post('organisations', [OrganisationController::class, 'store'])->name('organisations.store');
    Route::put('organisations/{organisation}/current', [OrganisationController::class, 'update'])->name('organisations.current.update');
    Route::get('organisation/members', [OrganisationMemberController::class, 'index'])->name('organisation.members.index');
    Route::post('organisation/members', [OrganisationMemberController::class, 'store'])->name('organisation.members.store');
    Route::patch('organisation/members/{membership}', [OrganisationMemberController::class, 'update'])->name('organisation.members.update');
    Route::delete('organisation/members/{membership}', [OrganisationMemberController::class, 'destroy'])->name('organisation.members.destroy');
    Route::get('organisation/audit', [OrganisationAuditController::class, 'index'])->name('organisation.audit.index');
    Route::get('organisation/sso', [OrganisationSsoController::class, 'show'])->name('organisation.sso.show');
    Route::put('organisation/sso', [OrganisationSsoController::class, 'update'])->middleware('throttle:10,1')->name('organisation.sso.update');
    Route::delete('organisation/sso', [OrganisationSsoController::class, 'destroy'])->name('organisation.sso.destroy');
    Route::post('organisation/sso/scim-token', [OrganisationScimTokenController::class, 'store'])->name('organisation.sso.scim-token.store');
    Route::delete('organisation/sso/scim-token', [OrganisationScimTokenController::class, 'destroy'])->name('organisation.sso.scim-token.destroy');
    Route::get('organisation/api-tokens', [OrganisationApiTokenController::class, 'index'])->name('organisation.api-tokens.index');
    Route::post('organisation/api-tokens', [OrganisationApiTokenController::class, 'store'])->middleware('throttle:20,1')->name('organisation.api-tokens.store');
    Route::delete('organisation/api-tokens/{apiToken}', [OrganisationApiTokenController::class, 'destroy'])->name('organisation.api-tokens.destroy');
    Route::get('organisation/types', [OrganisationTypeController::class, 'index'])->name('organisation.types.index');
    Route::post('organisation/types', [OrganisationTypeController::class, 'store'])->name('organisation.types.store');
    Route::get('organisation/types/{type}', [OrganisationTypeController::class, 'show'])->name('organisation.types.show');
    Route::delete('organisation/types/{type}', [OrganisationTypeController::class, 'destroy'])->name('organisation.types.destroy');
});

Route::middleware('auth')->group(function (): void {
    // User Profile...
    Route::get('settings', fn () => to_route('user-profile.edit'));
    Route::get('settings/profile', [UserProfileController::class, 'edit'])->name('user-profile.edit');
    Route::patch('settings/profile', [UserProfileController::class, 'update'])->name('user-profile.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    // User...
    Route::delete('user', [UserController::class, 'destroy'])->name('user.destroy');

    // User Password...
    Route::get('settings/password', [UserPasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [UserPasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    // Appearance...
    Route::get('settings/appearance', fn () => Inertia::render('appearance/update'))->name('appearance.edit');

    // User Two-Factor Authentication...
    Route::get('settings/two-factor', [UserTwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    // User Passkeys...
    Route::get('settings/passkeys', [UserPasskeyController::class, 'index'])
        ->name('user-passkey.index');

    // Single sign-on...
    Route::get('sso/required', [SsoRequiredController::class, 'show'])->middleware(EnsureCurrentOrganisation::class)->name('sso.required');
    Route::post('sso/required', [SsoRequiredController::class, 'store'])->middleware([EnsureCurrentOrganisation::class, 'throttle:sso'])->name('sso.required.store');
    Route::get('settings/sso', [UserSsoIdentityController::class, 'index'])->name('user-sso.index');
    Route::post('settings/sso', [UserSsoIdentityController::class, 'store'])->middleware('throttle:sso')->name('user-sso.store');
    Route::delete('settings/sso/{identity}', [UserSsoIdentityController::class, 'destroy'])->name('user-sso.destroy');
});

// Where identity providers send people back, signed in or linking.
Route::get('sso/callback', [SsoCallbackController::class, 'show'])->middleware('throttle:sso-callback')->name('sso.callback');

Route::middleware('guest')->group(function (): void {
    // Single sign-on...
    Route::get('sso', [SsoSessionController::class, 'create'])->name('sso.create');
    Route::post('sso', [SsoSessionController::class, 'store'])->middleware('throttle:sso')->name('sso.store');

    // User...
    Route::get('register', [UserController::class, 'create'])
        ->name('register');
    Route::post('register', [UserController::class, 'store'])
        ->name('register.store');

    // User Password...
    Route::get('reset-password/{token}', [UserPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('reset-password', [UserPasswordController::class, 'store'])
        ->name('password.store');

    // User Email Reset Notification...
    Route::get('forgot-password', [UserEmailResetNotificationController::class, 'create'])
        ->name('password.request');
    Route::post('forgot-password', [UserEmailResetNotificationController::class, 'store'])
        ->name('password.email');

    // Session...
    Route::get('login', [SessionController::class, 'create'])
        ->name('login');
    Route::post('login', [SessionController::class, 'store'])
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    // User Email Verification...
    Route::get('verify-email', [UserEmailVerificationNotificationController::class, 'create'])
        ->name('verification.notice');
    Route::post('email/verification-notification', [UserEmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // User Email Verification...
    Route::get('verify-email/{id}/{hash}', [UserEmailVerificationController::class, 'update'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // Session...
    Route::post('logout', [SessionController::class, 'destroy'])
        ->name('logout');
});

// Passkey Endpoints...
Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('user-passkey.index'),
    'manage' => route('user-passkey.index'),
]))->name('well-known.passkeys');
