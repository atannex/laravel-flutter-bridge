<?php

declare(strict_types=1);

use Atannex\LaravelFlutterBridge\Http\Controllers\AuthController;
use Atannex\LaravelFlutterBridge\Http\Controllers\BroadcastAuthController;
use Atannex\LaravelFlutterBridge\Http\Controllers\ConnectionController;
use Atannex\LaravelFlutterBridge\Http\Controllers\DeviceController;
use Atannex\LaravelFlutterBridge\Http\Controllers\Fortify\AccountController;
use Atannex\LaravelFlutterBridge\Http\Controllers\Fortify\EmailVerificationController;
use Atannex\LaravelFlutterBridge\Http\Controllers\Fortify\PasskeyController;
use Atannex\LaravelFlutterBridge\Http\Controllers\Fortify\PasswordResetController;
use Atannex\LaravelFlutterBridge\Http\Controllers\Fortify\TwoFactorAuthenticationController;
use Atannex\LaravelFlutterBridge\Http\Controllers\HandshakeController;
use Atannex\LaravelFlutterBridge\Http\Controllers\SocialAuthController;
use Atannex\LaravelFlutterBridge\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Flutter Bridge Routes (protocol v2)
|--------------------------------------------------------------------------
|
| Route names are "flutter.<endpoint key>"; the handshake publishes them as
| its `endpoints` map. flutter.headers and flutter.response wrap everything
| (including the configured middleware) so throttling and other failures are
| enveloped with a meta.error_code.
|
| Every route carries one of the named limiters registered by the service
| provider (flutter-auth, flutter-2fa, flutter-refresh, flutter-connect,
| flutter-heartbeat, flutter-api, and for the Fortify endpoints
| flutter-password-reset, flutter-codes, flutter-verification,
| flutter-confirm-password, flutter-account), configured under
| flutter-bridge.rate_limits.
|
*/

// The bridge guards run before the configured middleware so that, after
// Laravel's middleware priority sorting, they still execute ahead of
// auth:<guard> (an outdated client gets 426 and a revoked session gets 403
// connection_revoked rather than a generic 401).
$middleware = array_values(array_unique(array_merge(
    ['flutter.headers', 'flutter.response', 'flutter.app', 'flutter.version', 'flutter.connection'],
    (array) config('flutter-bridge.middleware', ['api'])
)));

Route::prefix(config('flutter-bridge.prefix', 'api/flutter'))
    ->middleware($middleware)
    ->group(function () {
        $authGuard = config('flutter-bridge.auth.guard', 'sanctum');
        $broadcastPath = trim((string) config('flutter-bridge.broadcasting.auth_path', 'broadcasting/auth'), '/');

        // Discovery
        Route::get('/ping', [HandshakeController::class, 'ping'])->middleware('throttle:flutter-api')->name('flutter.ping');
        Route::get('/handshake', [HandshakeController::class, 'handshake'])->middleware('throttle:flutter-api')->name('flutter.handshake');
        Route::get('/config', [HandshakeController::class, 'config'])->middleware('throttle:flutter-api')->name('flutter.config');

        // Public Auth
        Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:flutter-auth')->name('flutter.auth.login');
        Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:flutter-auth')->name('flutter.auth.register');
        Route::post('/auth/social', [SocialAuthController::class, 'authenticate'])->middleware('throttle:flutter-auth')->name('flutter.auth.social');
        Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:flutter-refresh')->name('flutter.auth.refresh');
        Route::post('/auth/2fa/send-otp', [TwoFactorController::class, 'sendOtp'])->middleware('throttle:flutter-auth')->name('flutter.auth.2fa.send');
        Route::post('/auth/2fa/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:flutter-2fa')->name('flutter.auth.2fa.verify');

        // Laravel Fortify: forgot / reset password (docs/FORTIFY.md)
        Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:flutter-password-reset')->name('flutter.auth.password.forgot');
        Route::post('/auth/reset-password/verify-code', [PasswordResetController::class, 'verifyCode'])->middleware('throttle:flutter-codes')->name('flutter.auth.password.verify_code');
        Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:flutter-codes')->name('flutter.auth.password.reset');

        // Anonymous or Authenticated Device Registration (optional bearer)
        Route::post('/devices/register', [DeviceController::class, 'register'])->middleware('throttle:flutter-api')->name('flutter.devices.register');
        Route::delete('/devices/{device}', [DeviceController::class, 'unregister'])->middleware('throttle:flutter-api')->name('flutter.devices.unregister');

        // Connection sessions (optional bearer)
        Route::post('/connections/connect', [ConnectionController::class, 'connect'])->middleware('throttle:flutter-connect')->name('flutter.connections.connect');
        Route::post('/connections/{connection}/heartbeat', [ConnectionController::class, 'heartbeat'])->middleware('throttle:flutter-heartbeat')->name('flutter.connections.heartbeat');
        Route::post('/connections/{connection}/disconnect', [ConnectionController::class, 'disconnect'])->middleware('throttle:flutter-heartbeat')->name('flutter.connections.disconnect');

        // Protected Auth & Services
        Route::middleware(["auth:{$authGuard}", 'throttle:flutter-api'])->group(function () use ($broadcastPath) {
            Route::get('/auth/me', [AuthController::class, 'me'])->name('flutter.auth.me');
            Route::post('/auth/logout', [AuthController::class, 'logout'])->name('flutter.auth.logout');

            // WebSockets / Broadcasting Authentication for Flutter Bearer token
            Route::post('/'.$broadcastPath, [BroadcastAuthController::class, 'authenticate'])
                ->name('flutter.broadcasting.auth');

            // Session management for the authenticated user
            Route::get('/connections', [ConnectionController::class, 'index'])->name('flutter.connections.index');
            Route::delete('/connections', [ConnectionController::class, 'revokeOthers'])->name('flutter.connections.revoke_others');
            Route::delete('/connections/{connection}', [ConnectionController::class, 'revoke'])->name('flutter.connections.revoke');

            // Laravel Fortify: email verification, profile, password, confirmation (docs/FORTIFY.md)
            Route::post('/auth/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:flutter-verification')->name('flutter.auth.email.send');
            Route::post('/auth/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:flutter-codes')->name('flutter.auth.email.verify');
            Route::post('/auth/user/confirm-password', [AccountController::class, 'confirmPassword'])->middleware('throttle:flutter-confirm-password')->name('flutter.auth.user.confirm_password');
            Route::get('/auth/user/confirmed-password-status', [AccountController::class, 'confirmedPasswordStatus'])->name('flutter.auth.user.confirmed_password_status');

            Route::middleware('throttle:flutter-account')->group(function () {
                Route::put('/auth/user/profile-information', [AccountController::class, 'updateProfile'])->name('flutter.auth.user.profile');
                Route::put('/auth/user/password', [AccountController::class, 'updatePassword'])->name('flutter.auth.user.password');

                // Two-factor (authenticator app) management
                Route::post('/auth/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'enable'])->name('flutter.auth.two_factor.enable');
                Route::delete('/auth/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'disable'])->name('flutter.auth.two_factor.disable');
                Route::post('/auth/user/confirmed-two-factor-authentication', [TwoFactorAuthenticationController::class, 'confirm'])->name('flutter.auth.two_factor.confirm');
                Route::get('/auth/user/two-factor-qr-code', [TwoFactorAuthenticationController::class, 'qrCode'])->name('flutter.auth.two_factor.qr_code');
                Route::get('/auth/user/two-factor-secret-key', [TwoFactorAuthenticationController::class, 'secretKey'])->name('flutter.auth.two_factor.secret_key');
                Route::get('/auth/user/two-factor-recovery-codes', [TwoFactorAuthenticationController::class, 'recoveryCodes'])->name('flutter.auth.two_factor.recovery_codes');
                Route::post('/auth/user/two-factor-recovery-codes', [TwoFactorAuthenticationController::class, 'regenerateRecoveryCodes'])->name('flutter.auth.two_factor.regenerate_recovery_codes');

                // Passkeys (laravel/passkeys)
                Route::get('/auth/user/passkeys', [PasskeyController::class, 'index'])->name('flutter.auth.passkeys.index');
                Route::patch('/auth/user/passkeys/{passkey}', [PasskeyController::class, 'update'])->name('flutter.auth.passkeys.update');
                Route::delete('/auth/user/passkeys/{passkey}', [PasskeyController::class, 'destroy'])->name('flutter.auth.passkeys.destroy');
            });
        });
    });
