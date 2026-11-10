<?php

declare(strict_types=1);

use Atannex\LaravelFlutterBridge\Http\Controllers\AuthController;
use Atannex\LaravelFlutterBridge\Http\Controllers\BroadcastAuthController;
use Atannex\LaravelFlutterBridge\Http\Controllers\ConnectionController;
use Atannex\LaravelFlutterBridge\Http\Controllers\DeviceController;
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
| flutter-heartbeat, flutter-api), configured under flutter-bridge.rate_limits.
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
        });
    });
