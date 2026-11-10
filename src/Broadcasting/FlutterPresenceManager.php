<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Broadcasting;

use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Atannex\LaravelFlutterBridge\Support\Payload;

/**
 * Backwards-compatible presence API backed by connection sessions.
 *
 * @deprecated Use FlutterBridge::connections() (FlutterConnectionManager) instead.
 */
class FlutterPresenceManager
{
    public function __construct(
        protected ?FlutterConnectionManager $connections = null
    ) {}

    /**
     * Mark a user/device as actively connected by touching its sessions.
     *
     * The $ttlMinutes argument is kept for BC; online status is now driven by
     * flutter-bridge.connections.timeout.
     */
    public function markOnline(int|string $userId, ?string $deviceId = null, int $ttlMinutes = 5): void
    {
        $query = FlutterConnection::query()->forUser($userId)->notRevoked();

        if ($deviceId !== null) {
            $query->where('device_id', $deviceId);

            FlutterDevice::query()->where('device_id', $deviceId)->update([
                'last_active_at' => now(),
            ]);
        }

        $query->update([
            'status' => FlutterConnection::STATUS_ACTIVE,
            'last_seen_at' => now(),
            'disconnected_at' => null,
        ]);
    }

    /**
     * Mark every active session of a user as disconnected.
     */
    public function markOffline(int|string $userId): void
    {
        $manager = $this->manager();

        FlutterConnection::query()
            ->forUser($userId)
            ->active()
            ->get()
            ->each(fn (FlutterConnection $connection) => $manager->disconnect($connection));
    }

    /**
     * Check if a user is currently online.
     */
    public function isOnline(int|string $userId): bool
    {
        return $this->manager()->isUserOnline($userId);
    }

    /**
     * Get active presence details for a user.
     *
     * @return array<string, mixed>|null
     */
    public function getPresence(int|string $userId): ?array
    {
        /** @var FlutterConnection|null $connection */
        $connection = $this->manager()->online()
            ->forUser($userId)
            ->orderByDesc('last_seen_at')
            ->first();

        if ($connection === null) {
            return null;
        }

        return [
            'online' => true,
            'device_id' => $connection->device_id,
            'connection_id' => (string) $connection->getKey(),
            'last_ping_at' => Payload::date($connection->last_seen_at),
        ];
    }

    /**
     * Resolve the connection manager.
     */
    protected function manager(): FlutterConnectionManager
    {
        return $this->connections ??= app(FlutterConnectionManager::class);
    }
}
