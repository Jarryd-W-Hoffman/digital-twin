<?php

declare(strict_types=1);

use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\OrganisationMemberController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TwinAssetController;
use App\Http\Controllers\TwinAssetFileController;
use App\Http\Controllers\TwinController;
use App\Http\Controllers\TwinGatewayTokenController;
use App\Http\Controllers\TwinVersionController;
use App\Http\Controllers\TwinVersionRestorationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserEmailResetNotificationController;
use App\Http\Controllers\UserEmailVerificationController;
use App\Http\Controllers\UserEmailVerificationNotificationController;
use App\Http\Controllers\UserPasskeyController;
use App\Http\Controllers\UserPasswordController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UserTwoFactorAuthenticationController;
use App\Http\Middleware\EnsureCurrentOrganisation;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome'))->name('home');

// The sample twins run in the browser without an account.
Route::get('demo', fn () => Inertia::render('demo'))->name('demo');

Route::middleware(['auth', 'verified', EnsureCurrentOrganisation::class])->group(function (): void {
    Route::get('dashboard', fn () => to_route('twins.index'))->name('dashboard');

    // Twins...
    Route::resource('twins', TwinController::class)->except(['edit']);
    Route::post('twins/{twin}/versions', [TwinVersionController::class, 'store'])->name('twins.versions.store');
    Route::get('twins/{twin}/versions/{version}', [TwinVersionController::class, 'show'])->scopeBindings()->name('twins.versions.show');
    Route::post('twins/{twin}/versions/{version}/restore', [TwinVersionRestorationController::class, 'store'])->scopeBindings()->name('twins.versions.restore');
    Route::post('twins/{twin}/assets', [TwinAssetController::class, 'store'])->name('twins.assets.store');
    Route::delete('twins/{twin}/assets/{asset}', [TwinAssetController::class, 'destroy'])->scopeBindings()->name('twins.assets.destroy');
    Route::get('twins/{twin}/assets/{asset}/files/{path}', [TwinAssetFileController::class, 'show'])
        ->where('path', '.*')
        ->scopeBindings()
        ->name('twins.assets.files.show');
    Route::post('twins/{twin}/gateway-token', [TwinGatewayTokenController::class, 'store'])->name('twins.gateway-token.store');
    Route::delete('twins/{twin}/gateway-token', [TwinGatewayTokenController::class, 'destroy'])->name('twins.gateway-token.destroy');

    // Organisations...
    Route::post('organisations', [OrganisationController::class, 'store'])->name('organisations.store');
    Route::put('organisations/{organisation}/current', [OrganisationController::class, 'update'])->name('organisations.current.update');
    Route::get('organisation/members', [OrganisationMemberController::class, 'index'])->name('organisation.members.index');
    Route::post('organisation/members', [OrganisationMemberController::class, 'store'])->name('organisation.members.store');
    Route::patch('organisation/members/{membership}', [OrganisationMemberController::class, 'update'])->name('organisation.members.update');
    Route::delete('organisation/members/{membership}', [OrganisationMemberController::class, 'destroy'])->name('organisation.members.destroy');
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
});

Route::middleware('guest')->group(function (): void {
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
