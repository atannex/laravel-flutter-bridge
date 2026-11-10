<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Services;

use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class FlutterBridgeManager
{
    /**
     * Server package / bridge protocol version (X-Laravel-Flutter-Bridge header).
     */
    public const VERSION = '2.0.0';

    public function __construct(
        protected ?Container $container = null
    ) {}

    /**
     * Connection session manager.
     */
    public function connections(): FlutterConnectionManager
    {
        return $this->resolve(FlutterConnectionManager::class);
    }

    /**
     * Client app manager.
     */
    public function apps(): FlutterAppManager
    {
        return $this->resolve(FlutterAppManager::class);
    }

    /**
     * Access / refresh token manager.
     */
    public function tokens(): FlutterTokenManager
    {
        return $this->resolve(FlutterTokenManager::class);
    }

    /**
     * Server package version.
     */
    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * Whether a bridge feature is enabled (as advertised in the handshake).
     */
    public function featureEnabled(string $feature): bool
    {
        $authEnabled = (bool) config('flutter-bridge.auth.enabled', true);

        return match ($feature) {
            'auth' => $authEnabled,
            'registration', 'social_auth', 'two_factor' => $authEnabled && (bool) config("flutter-bridge.features.{$feature}", true),
            'devices' => (bool) config('flutter-bridge.devices.enabled', true),
            'broadcasting' => (bool) config('flutter-bridge.broadcasting.enabled', true),
            'connections' => (bool) config('flutter-bridge.connections.enabled', true),
            'push' => (bool) config('flutter-bridge.features.push', true),
            default => (bool) config("flutter-bridge.features.{$feature}", false),
        };
    }

    /**
     * All feature flags advertised in the handshake.
     *
     * @return array<string, bool>
     */
    public function features(): array
    {
        $features = [];

        foreach (['auth', 'registration', 'social_auth', 'two_factor', 'devices', 'broadcasting', 'connections', 'push'] as $feature) {
            $features[$feature] = $this->featureEnabled($feature);
        }

        return $features;
    }

    /**
     * Return a standardized success JSON response.
     *
     * @param array<string, mixed> $meta
     */
    public function success(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = 200,
        array $meta = []
    ): JsonResponse {
        return FlutterResponse::success($data, $message, $statusCode, $meta);
    }

    /**
     * Return a standardized error JSON response.
     *
     * @param array<string, mixed> $meta
     */
    public function error(
        string $message = 'An error occurred',
        int $statusCode = 400,
        mixed $errors = null,
        array $meta = [],
        ?string $errorCode = null
    ): JsonResponse {
        return FlutterResponse::error($message, $statusCode, $errors, $meta, $errorCode);
    }

    /**
     * Return a standardized paginated JSON response.
     *
     * @param array<string, mixed> $meta
     */
    public function paginated(
        Paginator $paginator,
        string $message = 'Data retrieved successfully',
        array $meta = []
    ): JsonResponse {
        return FlutterResponse::paginated($paginator, $message, $meta);
    }

    /**
     * Register or update a Flutter client device.
     *
     * A device row owned by a user is never re-assigned to an anonymous caller
     * or to another user: anonymous registrations only touch ownerless rows.
     *
     * @param array<string, mixed> $attributes
     */
    public function registerDevice(
        int|string|null $userId,
        string $deviceId,
        array $attributes = []
    ): FlutterDevice {
        /** @var FlutterDevice|null $device */
        $device = FlutterDevice::query()
            ->where('device_id', $deviceId)
            ->when(
                $userId !== null,
                fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('user_id', $userId)->orWhereNull('user_id')),
                fn (Builder $q) => $q->whereNull('user_id')
            )
            ->orderByRaw('case when user_id is null then 1 else 0 end')
            ->first();

        $attributes = array_intersect_key($attributes, array_flip([
            'fcm_token', 'platform', 'device_name', 'os_version', 'app_version', 'metadata',
        ]));
        $attributes['last_active_at'] = now();

        if ($device === null) {
            /** @var FlutterDevice $device */
            $device = FlutterDevice::query()->create(array_merge($attributes, [
                'device_id' => $deviceId,
                'user_id' => $userId,
            ]));

            return $device;
        }

        if ($device->user_id === null && $userId !== null) {
            $attributes['user_id'] = $userId;
        }

        $device->fill($attributes)->save();

        return $device;
    }

    /**
     * Resolve a service from the container.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function resolve(string $class): object
    {
        return ($this->container ?? app())->make($class);
    }
}
