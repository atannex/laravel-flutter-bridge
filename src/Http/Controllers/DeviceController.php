<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers;

use Atannex\LaravelFlutterBridge\Events\DeviceRegistered;
use Atannex\LaravelFlutterBridge\Events\DeviceUnregistered;
use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeviceController extends Controller
{
    /**
     * Register or update a Flutter client device with push notification token.
     *
     * Public route with optional bearer: when a valid token is sent the device
     * is attached to that user.
     */
    public function register(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:255'],
            'fcm_token' => ['nullable', 'string', 'max:2048'],
            'platform' => ['required', 'string', 'in:android,ios,web,macos,windows,linux,fuchsia'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:64'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = BridgeRequest::user($request);

        $device = app('flutter.bridge')->registerDevice(
            $user?->getAuthIdentifier(),
            $validated['device_id'],
            [
                'fcm_token' => $validated['fcm_token'] ?? null,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
            ]
        );

        event(new DeviceRegistered($device));

        return FlutterResponse::success([
            'device' => $device,
        ], 'Device registered successfully', 200);
    }

    /**
     * Unregister a device (e.g. upon app uninstall or push notification permission revoked).
     *
     * Authenticated callers remove their own row; anonymous callers can only
     * remove ownerless rows and never a device that belongs to a user.
     */
    public function unregister(Request $request, string $device): JsonResponse
    {
        $this->ensureEnabled();

        $user = BridgeRequest::user($request);
        $query = FlutterDevice::query()->where('device_id', $device);

        if ($user !== null) {
            $query->where('user_id', $user->getAuthIdentifier());
        } else {
            $query->whereNull('user_id');
        }

        $deleted = $query->delete();

        if ($deleted) {
            $userId = $user?->getAuthIdentifier();
            event(new DeviceUnregistered($device, is_int($userId) ? $userId : null));
        }

        return FlutterResponse::success([
            'unregistered' => (bool) $deleted,
        ], $deleted ? 'Device unregistered' : 'Device not found');
    }

    /**
     * Abort with 403 feature_disabled when device management is turned off.
     *
     * @throws FlutterBridgeException
     */
    protected function ensureEnabled(): void
    {
        if (! config('flutter-bridge.devices.enabled', true)) {
            throw FlutterBridgeException::featureDisabled('devices');
        }
    }
}
