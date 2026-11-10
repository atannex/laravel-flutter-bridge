<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Services;

use Atannex\LaravelFlutterBridge\Events\FlutterCommandQueued;
use Atannex\LaravelFlutterBridge\Events\FlutterConnectionDisconnected;
use Atannex\LaravelFlutterBridge\Events\FlutterConnectionEstablished;
use Atannex\LaravelFlutterBridge\Events\FlutterConnectionRevoked;
use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Models\FlutterApp;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterConnectionCommand;
use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Manages connection sessions: connect / resume, heartbeats, revocation and
 * server -> client commands.
 */
class FlutterConnectionManager
{
    /**
     * Built-in command types understood by the Flutter client.
     */
    public const COMMAND_REVOKED = 'revoked';

    public const COMMAND_LOGOUT = 'logout';

    public const COMMAND_REFRESH_CONFIG = 'refresh_config';

    public const COMMAND_FORCE_UPDATE = 'force_update';

    public const COMMAND_MESSAGE = 'message';

    public function __construct(
        protected FlutterTokenManager $tokens
    ) {}

    /**
     * Open a new session, or resume the non-revoked session of the same (app, device).
     *
     * The returned model's `wasRecentlyCreated` flag tells whether it is new.
     *
     * @param array<string, mixed> $attributes device_id, platform, app_version, device_name,
     *                                         os_version, fcm_token, bridge_version, metadata
     */
    public function connect(
        array $attributes,
        ?FlutterApp $app = null,
        ?Authenticatable $user = null,
        ?Request $request = null
    ): FlutterConnection {
        $deviceId = (string) $attributes['device_id'];
        $userId = $user?->getAuthIdentifier();

        $connection = FlutterConnection::query()
            ->where('device_id', $deviceId)
            ->when(
                $app !== null,
                fn (Builder $q) => $q->where('app_id', $app?->getKey()),
                fn (Builder $q) => $q->whereNull('app_id')
            )
            ->notRevoked()
            ->orderByDesc('last_seen_at')
            ->get()
            // Never hand a user's session to an anonymous caller or to another user.
            ->first(fn (FlutterConnection $c) => $c->user_id === null || $c->belongsToUser($userId));

        $values = array_filter([
            'platform' => $attributes['platform'] ?? null,
            'device_name' => $attributes['device_name'] ?? null,
            'app_version' => $attributes['app_version'] ?? $request?->header('X-Flutter-App-Version'),
            'os_version' => $attributes['os_version'] ?? null,
            'bridge_version' => $attributes['bridge_version'] ?? $request?->header('X-Flutter-Bridge-Version'),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ], fn ($value) => $value !== null);

        if (array_key_exists('metadata', $attributes) && is_array($attributes['metadata'])) {
            $values['metadata'] = $attributes['metadata'];
        }

        $now = now();

        if ($connection instanceof FlutterConnection) {
            $connection->fill($values);
            $connection->forceFill([
                'status' => FlutterConnection::STATUS_ACTIVE,
                'last_seen_at' => $now,
                'disconnected_at' => null,
                'user_id' => $connection->user_id ?? $userId,
            ])->save();
        } else {
            /** @var FlutterConnection $connection */
            $connection = FlutterConnection::query()->create(array_merge($values, [
                'app_id' => $app?->getKey(),
                'user_id' => $userId,
                'device_id' => $deviceId,
                'status' => FlutterConnection::STATUS_ACTIVE,
                'connected_at' => $now,
                'last_seen_at' => $now,
            ]));
        }

        $this->syncDevice($connection, $attributes['fcm_token'] ?? null, array_key_exists('fcm_token', $attributes));

        if ($app !== null) {
            $connection->setRelation('flutterApp', $app);
        }

        event(new FlutterConnectionEstablished($connection, !$connection->wasRecentlyCreated));

        return $connection;
    }

    /**
     * Record a heartbeat and return (and mark delivered) all pending commands.
     *
     * @param array<string, mixed> $attributes state, fcm_token, metadata
     * @return array{connection: FlutterConnection, commands: EloquentCollection<int, FlutterConnectionCommand>}
     *
     * @throws FlutterBridgeException
     */
    public function heartbeat(
        FlutterConnection|string $connection,
        array $attributes = [],
        ?Authenticatable $user = null
    ): array {
        $connection = $this->findOrFail($connection);

        if ($connection->isRevoked()) {
            throw FlutterBridgeException::connectionRevoked();
        }

        $updates = [
            'status' => FlutterConnection::STATUS_ACTIVE,
            'last_seen_at' => now(),
            'disconnected_at' => null,
        ];

        if (!empty($attributes['state']) && in_array($attributes['state'], ['foreground', 'background'], true)) {
            $updates['state'] = $attributes['state'];
        }

        if (isset($attributes['metadata']) && is_array($attributes['metadata'])) {
            $updates['metadata'] = array_merge($connection->metadata ?? [], $attributes['metadata']);
        }

        if ($connection->user_id === null && $user !== null) {
            $updates['user_id'] = $user->getAuthIdentifier();
        }

        $connection->forceFill($updates)->save();

        if (array_key_exists('fcm_token', $attributes)) {
            $this->syncDevice($connection, $attributes['fcm_token'], true);
        }

        $commands = $this->pendingCommandsFor($connection);
        $this->markDelivered($commands);

        return ['connection' => $connection, 'commands' => $commands];
    }

    /**
     * Mark a session as disconnected.
     */
    public function disconnect(FlutterConnection|string $connection): FlutterConnection
    {
        $connection = $this->findOrFail($connection);

        if ($connection->isRevoked() || $connection->status === FlutterConnection::STATUS_DISCONNECTED) {
            return $connection;
        }

        $connection->forceFill([
            'status' => FlutterConnection::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
        ])->save();

        event(new FlutterConnectionDisconnected($connection));

        return $connection;
    }

    /**
     * Revoke a session: mark it revoked, revoke its tokens, queue a "revoked"
     * command and fire FlutterConnectionRevoked.
     */
    public function revoke(FlutterConnection|string $connection, ?string $reason = null): FlutterConnection
    {
        $connection = $this->findOrFail($connection);

        if ($connection->isRevoked()) {
            return $connection;
        }

        $connection->forceFill([
            'status' => FlutterConnection::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();

        $this->tokens->revokeForConnection($connection, FlutterRefreshToken::REASON_CONNECTION_REVOKED);

        $this->sendCommand($connection, self::COMMAND_REVOKED, $reason !== null ? ['reason' => $reason] : []);

        event(new FlutterConnectionRevoked($connection, $reason));

        return $connection;
    }

    /**
     * Revoke every non-revoked session of a user, optionally keeping one.
     */
    public function revokeAllForUser(int|string $userId, ?string $exceptId = null, ?string $reason = null): int
    {
        $count = 0;

        FlutterConnection::query()
            ->forUser($userId)
            ->notRevoked()
            ->when($exceptId !== null, fn (Builder $q) => $q->where('id', '!=', $exceptId))
            ->get()
            ->each(function (FlutterConnection $connection) use ($reason, &$count) {
                $this->revoke($connection, $reason);
                $count++;
            });

        return $count;
    }

    /**
     * Queue a command for a session (pushed instantly when broadcasting is on).
     *
     * @param array<string, mixed> $payload
     * @param int|null $ttl Minutes the command stays deliverable (null = config, <= 0 = never expires).
     */
    public function sendCommand(
        FlutterConnection|string $connection,
        string $type,
        array $payload = [],
        ?int $ttl = null
    ): FlutterConnectionCommand {
        $connection = $this->findOrFail($connection);
        $ttl ??= config('flutter-bridge.connections.command_ttl') !== null
            ? (int) config('flutter-bridge.connections.command_ttl')
            : 0;

        /** @var FlutterConnectionCommand $command */
        $command = FlutterConnectionCommand::query()->create([
            'connection_id' => $connection->getKey(),
            'type' => $type,
            'payload' => $payload,
            'expires_at' => $ttl > 0 ? now()->addMinutes($ttl) : null,
        ]);

        event(new FlutterCommandQueued($command));

        return $command;
    }

    /**
     * Queue a command for every non-revoked session of a user.
     *
     * @param array<string, mixed> $payload
     * @return Collection<int, FlutterConnectionCommand>
     */
    public function sendCommandToUser(int|string $userId, string $type, array $payload = [], ?int $ttl = null): Collection
    {
        return FlutterConnection::query()
            ->forUser($userId)
            ->notRevoked()
            ->get()
            ->map(fn (FlutterConnection $connection) => $this->sendCommand($connection, $type, $payload, $ttl))
            ->values()
            ->toBase();
    }

    /**
     * Queue a command for every active session (optionally of one app).
     *
     * @param array<string, mixed> $payload
     * @return Collection<int, FlutterConnectionCommand>
     */
    public function sendCommandToAll(string $type, array $payload = [], ?int $ttl = null, ?int $appId = null): Collection
    {
        return $this->active()
            ->when($appId !== null, fn (Builder $q) => $q->where('app_id', $appId))
            ->get()
            ->map(fn (FlutterConnection $connection) => $this->sendCommand($connection, $type, $payload, $ttl))
            ->values()
            ->toBase();
    }

    /**
     * Undelivered, non-expired commands of a session, oldest first.
     *
     * @return EloquentCollection<int, FlutterConnectionCommand>
     */
    public function pendingCommandsFor(FlutterConnection|string $connection): EloquentCollection
    {
        $id = $connection instanceof FlutterConnection ? (string) $connection->getKey() : $connection;

        return FlutterConnectionCommand::query()
            ->where('connection_id', $id)
            ->pending()
            ->orderBy('id')
            ->get();
    }

    /**
     * Mark commands as delivered.
     *
     * @param iterable<FlutterConnectionCommand|int> $commands
     */
    public function markDelivered(iterable $commands): int
    {
        $ids = [];

        foreach ($commands as $command) {
            $ids[] = $command instanceof FlutterConnectionCommand ? $command->getKey() : $command;
        }

        if ($ids === []) {
            return 0;
        }

        return FlutterConnectionCommand::query()
            ->whereKey($ids)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);
    }

    /**
     * Find a session by id.
     */
    public function find(string $id): ?FlutterConnection
    {
        /** @var FlutterConnection|null $connection */
        $connection = FlutterConnection::query()->with('flutterApp')->find($id);

        return $connection;
    }

    /**
     * Find a session or fail with 404 connection_not_found.
     *
     * @throws FlutterBridgeException
     */
    public function findOrFail(FlutterConnection|string $connection): FlutterConnection
    {
        if ($connection instanceof FlutterConnection) {
            return $connection;
        }

        return $this->find($connection) ?? throw FlutterBridgeException::connectionNotFound();
    }

    /**
     * Query of sessions with status "active".
     *
     * @return Builder<FlutterConnection>
     */
    public function active(): Builder
    {
        return FlutterConnection::query()->active();
    }

    /**
     * Query of sessions that are active and seen within the timeout.
     *
     * @return Builder<FlutterConnection>
     */
    public function online(): Builder
    {
        return FlutterConnection::query()->online();
    }

    /**
     * Sessions of a user, newest activity first.
     *
     * @return EloquentCollection<int, FlutterConnection>
     */
    public function forUser(int|string $userId, ?string $status = null): EloquentCollection
    {
        return FlutterConnection::query()
            ->with('flutterApp')
            ->forUser($userId)
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Whether the user has at least one online session.
     */
    public function isUserOnline(int|string $userId): bool
    {
        return FlutterConnection::query()->forUser($userId)->online()->exists();
    }

    /**
     * Touch last_seen_at, at most once every $throttleSeconds.
     */
    public function touch(FlutterConnection $connection, int $throttleSeconds = 0): void
    {
        if ($connection->last_seen_at !== null
            && $throttleSeconds > 0
            && $connection->last_seen_at->gt(now()->subSeconds($throttleSeconds))) {
            return;
        }

        $connection->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    /**
     * Delete ended / stale sessions, old commands and dead refresh tokens.
     *
     * @return array{connections: int, commands: int, refresh_tokens: int}
     */
    public function prune(?int $days = null): array
    {
        $days ??= (int) config('flutter-bridge.connections.prune_after_days', 30);
        $cutoff = now()->subDays(max(0, $days));

        $staleIds = FlutterConnection::query()
            ->where(function (Builder $q) use ($cutoff) {
                $q->where(function (Builder $q) use ($cutoff) {
                    $q->where('status', FlutterConnection::STATUS_REVOKED)->where('revoked_at', '<', $cutoff);
                })->orWhere(function (Builder $q) use ($cutoff) {
                    $q->where('status', FlutterConnection::STATUS_DISCONNECTED)->where('disconnected_at', '<', $cutoff);
                })->orWhere(function (Builder $q) use ($cutoff) {
                    $q->where(fn (Builder $q) => $q->where('last_seen_at', '<', $cutoff)->orWhereNull('last_seen_at'))
                        ->where('updated_at', '<', $cutoff);
                });
            })
            ->pluck('id')
            ->all();

        $commands = 0;
        $connections = 0;

        foreach (array_chunk($staleIds, 500) as $chunk) {
            $commands += FlutterConnectionCommand::query()->whereIn('connection_id', $chunk)->delete();
            $connections += FlutterConnection::query()->whereKey($chunk)->delete();
        }

        $commands += FlutterConnectionCommand::query()
            ->where(function (Builder $q) use ($cutoff) {
                $q->where('delivered_at', '<', $cutoff)
                    ->orWhere('expires_at', '<', now())
                    ->orWhere(fn (Builder $q) => $q->whereNull('delivered_at')->where('created_at', '<', $cutoff));
            })
            ->delete();

        $refreshTokens = FlutterRefreshToken::query()
            ->where(function (Builder $q) use ($cutoff) {
                $q->where('expires_at', '<', $cutoff)->orWhere('revoked_at', '<', $cutoff);
            })
            ->delete();

        return [
            'connections' => (int) $connections,
            'commands' => (int) $commands,
            'refresh_tokens' => (int) $refreshTokens,
        ];
    }

    /**
     * Aggregate session statistics.
     *
     * @return array{total: int, active: int, online: int, disconnected: int, revoked: int, users_online: int, pending_commands: int}
     */
    public function stats(): array
    {
        $byStatus = FlutterConnection::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus[FlutterConnection::STATUS_ACTIVE] ?? 0),
            'online' => $this->online()->count(),
            'disconnected' => (int) ($byStatus[FlutterConnection::STATUS_DISCONNECTED] ?? 0),
            'revoked' => (int) ($byStatus[FlutterConnection::STATUS_REVOKED] ?? 0),
            'users_online' => $this->online()->whereNotNull('user_id')->distinct()->count('user_id'),
            'pending_commands' => FlutterConnectionCommand::query()->pending()->count(),
        ];
    }

    /**
     * Upsert the flutter_devices row of a session.
     */
    protected function syncDevice(FlutterConnection $connection, ?string $fcmToken, bool $fcmTokenProvided): void
    {
        if (!config('flutter-bridge.devices.enabled', true)) {
            return;
        }

        $attributes = array_filter([
            'platform' => $connection->platform,
            'device_name' => $connection->device_name,
            'os_version' => $connection->os_version,
            'app_version' => $connection->app_version,
        ], fn ($value) => $value !== null);

        if ($fcmTokenProvided) {
            $attributes['fcm_token'] = $fcmToken;
        }

        app('flutter.bridge')->registerDevice($connection->user_id, $connection->device_id, $attributes);
    }
}
