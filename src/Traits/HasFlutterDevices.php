<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Traits;

use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trait for authenticatable models that have associated Flutter client devices.
 */
trait HasFlutterDevices
{
    /**
     * Get the registered Flutter devices for this user.
     *
     * @return HasMany<FlutterDevice, $this>
     */
    public function flutterDevices(): HasMany
    {
        return $this->hasMany(FlutterDevice::class, 'user_id');
    }

    /**
     * Register or update a Flutter client device for this user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerFlutterDevice(string $deviceId, array $attributes = []): FlutterDevice
    {
        /** @var FlutterDevice $device */
        $device = $this->flutterDevices()->updateOrCreate(
            ['device_id' => $deviceId],
            array_merge($attributes, [
                'last_active_at' => now(),
            ])
        );

        return $device;
    }

    /**
     * Unregister a device by its client device ID.
     */
    public function unregisterFlutterDevice(string $deviceId): bool
    {
        return (bool) $this->flutterDevices()->where('device_id', $deviceId)->delete();
    }

    /**
     * Retrieve all active FCM push notification tokens for this user.
     *
     * @return list<string>
     */
    public function getActiveFcmTokens(): array
    {
        return $this->flutterDevices()
            ->withFcmToken()
            ->pluck('fcm_token')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
