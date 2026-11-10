<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Contracts;

use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Illuminate\Database\Eloquent\Relations\HasMany;

interface HasFlutterDevicesInterface
{
    /**
     * Get the registered Flutter devices for this model.
     */
    public function flutterDevices(): HasMany;

    /**
     * Register or update a Flutter client device for this model.
     *
     * @param array<string, mixed> $attributes
     */
    public function registerFlutterDevice(string $deviceId, array $attributes = []): FlutterDevice;

    /**
     * Unregister a device by its client device ID.
     */
    public function unregisterFlutterDevice(string $deviceId): bool;

    /**
     * Retrieve all active FCM push notification tokens.
     *
     * @return list<string>
     */
    public function getActiveFcmTokens(): array;
}
