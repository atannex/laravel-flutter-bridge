<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Services;

use Atannex\LaravelFlutterBridge\Models\FlutterApp;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Manages registered Flutter client apps (app keys, status and version policy).
 */
class FlutterAppManager
{
    /**
     * Attributes that may be set through create() / update().
     *
     * @var list<string>
     */
    protected const UPDATABLE = [
        'name',
        'status',
        'platforms',
        'min_version',
        'latest_version',
        'update_url',
        'settings',
    ];

    /**
     * Register a new client app. The returned model exposes the generated key.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(string $name, array $attributes = []): FlutterApp
    {
        $attributes = $this->sanitize($attributes);

        /** @var FlutterApp $app */
        $app = FlutterApp::query()->create(array_merge([
            'status' => FlutterApp::STATUS_ACTIVE,
        ], $attributes, [
            'name' => $name,
            'key' => $this->generateKey(),
        ]));

        return $app;
    }

    /**
     * Generate a unique app key ("fba_" + 40 random characters).
     */
    public function generateKey(): string
    {
        do {
            $key = FlutterApp::KEY_PREFIX.Str::random(40);
        } while (FlutterApp::query()->where('key', $key)->exists());

        return $key;
    }

    /**
     * Find an app by its key.
     */
    public function findByKey(string $key): ?FlutterApp
    {
        if ($key === '') {
            return null;
        }

        /** @var FlutterApp|null $app */
        $app = FlutterApp::query()->where('key', $key)->first();

        return $app;
    }

    /**
     * Resolve an app instance from a model, key or id.
     */
    public function resolve(FlutterApp|string|int $app): FlutterApp
    {
        if ($app instanceof FlutterApp) {
            return $app;
        }

        $model = is_string($app) && str_starts_with($app, FlutterApp::KEY_PREFIX)
            ? $this->findByKey($app)
            : FlutterApp::query()->find($app);

        if (! $model instanceof FlutterApp) {
            throw new InvalidArgumentException("Flutter app [{$app}] not found.");
        }

        return $model;
    }

    /**
     * All registered apps.
     *
     * @return Collection<int, FlutterApp>
     */
    public function all(): Collection
    {
        return FlutterApp::query()->orderBy('name')->get();
    }

    /**
     * Update an app.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(FlutterApp|string|int $app, array $attributes): FlutterApp
    {
        $app = $this->resolve($app);
        $app->fill($this->sanitize($attributes));
        $app->save();

        return $app;
    }

    /**
     * Suspend an app: every request carrying its key receives 403 app_suspended.
     */
    public function suspend(FlutterApp|string|int $app): FlutterApp
    {
        return $this->update($app, ['status' => FlutterApp::STATUS_SUSPENDED]);
    }

    /**
     * Re-activate a suspended app.
     */
    public function activate(FlutterApp|string|int $app): FlutterApp
    {
        return $this->update($app, ['status' => FlutterApp::STATUS_ACTIVE]);
    }

    /**
     * Delete an app, revoking its live sessions first.
     */
    public function delete(FlutterApp|string|int $app): bool
    {
        $app = $this->resolve($app);
        $connections = app(FlutterConnectionManager::class);

        FlutterConnection::query()
            ->where('app_id', $app->getKey())
            ->notRevoked()
            ->each(fn (FlutterConnection $connection) => $connections->revoke($connection, 'app_deleted'));

        FlutterConnection::query()->where('app_id', $app->getKey())->update(['app_id' => null]);

        return (bool) $app->delete();
    }

    /**
     * Record that the app has just connected (throttled to one write per minute).
     */
    public function touch(FlutterApp $app): void
    {
        if ($app->last_connected_at === null || $app->last_connected_at->lt(now()->subMinute())) {
            $app->forceFill(['last_connected_at' => now()])->saveQuietly();
        }
    }

    /**
     * Return upgrade details when the client version is below the effective minimum.
     *
     * @return array{current_version: string, min_version: string, latest_version: string|null, update_url: string|null}|null
     */
    public function upgradeRequirement(?FlutterApp $app, ?string $clientVersion): ?array
    {
        if ($clientVersion === null || trim($clientVersion) === '') {
            return null;
        }

        $min = $app?->effectiveMinVersion() ?? $this->globalMinVersion();

        if ($min === null || version_compare($clientVersion, $min, '>=')) {
            return null;
        }

        return [
            'current_version' => $clientVersion,
            'min_version' => $min,
            'latest_version' => $app?->latest_version,
            'update_url' => $app?->update_url,
        ];
    }

    /**
     * Global minimum client version from configuration.
     */
    protected function globalMinVersion(): ?string
    {
        $min = config('flutter-bridge.connections.min_app_version');

        return $min !== null && $min !== '' ? (string) $min : null;
    }

    /**
     * Normalize mutable attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function sanitize(array $attributes): array
    {
        $attributes = array_intersect_key($attributes, array_flip(self::UPDATABLE));

        if (array_key_exists('status', $attributes)
            && ! in_array($attributes['status'], [FlutterApp::STATUS_ACTIVE, FlutterApp::STATUS_SUSPENDED], true)) {
            throw new InvalidArgumentException('App status must be "active" or "suspended".');
        }

        if (array_key_exists('platforms', $attributes)) {
            $platforms = $attributes['platforms'];

            if (is_string($platforms)) {
                $platforms = explode(',', $platforms);
            }

            $platforms = is_array($platforms)
                ? array_values(array_unique(array_filter(array_map(
                    fn ($platform) => strtolower(trim((string) $platform)),
                    $platforms
                ))))
                : [];

            $attributes['platforms'] = $platforms === [] ? null : $platforms;
        }

        foreach (['min_version', 'latest_version', 'update_url'] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] === '') {
                $attributes[$key] = null;
            }
        }

        return $attributes;
    }
}
