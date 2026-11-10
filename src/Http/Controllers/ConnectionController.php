<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterConnectionCommand;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ConnectionController extends Controller
{
    /**
     * Supported client platforms.
     */
    protected const PLATFORMS = 'android,ios,web,macos,windows,linux,fuchsia';

    public function __construct(
        protected FlutterConnectionManager $connections
    ) {}

    /**
     * Open or resume a connection session (201 new, 200 resumed).
     */
    public function connect(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:' . self::PLATFORMS],
            'app_version' => ['nullable', 'string', 'max:64'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:64'],
            'fcm_token' => ['nullable', 'string', 'max:2048'],
            'bridge_version' => ['nullable', 'string', 'max:32'],
            'metadata' => ['nullable', 'array'],
        ]);

        $connection = $this->connections->connect(
            $validated,
            BridgeRequest::app($request),
            BridgeRequest::user($request),
            $request
        );

        $created = $connection->wasRecentlyCreated;

        return FlutterResponse::success([
            'connection' => $connection->toBridgeArray((string) $connection->getKey()),
            'heartbeat_interval' => $this->heartbeatInterval(),
            'timeout' => FlutterConnection::timeout(),
            'server_time' => now()->toIso8601String(),
        ], $created ? 'Connection established' : 'Connection resumed', $created ? 201 : 200);
    }

    /**
     * Keep a session alive and deliver pending commands.
     */
    public function heartbeat(Request $request, string $connection): JsonResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'state' => ['nullable', 'string', 'in:foreground,background'],
            'fcm_token' => ['nullable', 'string', 'max:2048'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = BridgeRequest::user($request);
        $model = $this->findAccessible($connection, $user);

        $result = $this->connections->heartbeat($model, $validated, $user);

        return FlutterResponse::success([
            'connection' => $result['connection']->toBridgeArray($this->currentId($request, $model)),
            'commands' => $result['commands']
                ->map(fn (FlutterConnectionCommand $command) => $command->toBridgeArray())
                ->values()
                ->all(),
            'server_time' => now()->toIso8601String(),
            'heartbeat_interval' => $this->heartbeatInterval(),
        ], 'Heartbeat received');
    }

    /**
     * Mark a session as disconnected.
     */
    public function disconnect(Request $request, string $connection): JsonResponse
    {
        $this->ensureEnabled();

        $model = $this->findAccessible($connection, BridgeRequest::user($request));
        $model = $this->connections->disconnect($model);

        return FlutterResponse::success([
            'connection' => $model->toBridgeArray($this->currentId($request, $model)),
        ], 'Connection disconnected');
    }

    /**
     * List the authenticated user's sessions, newest activity first.
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:active,disconnected,revoked'],
        ]);

        $currentId = BridgeRequest::connectionId($request);
        $connections = $this->connections->forUser($this->userId($request), $validated['status'] ?? null);

        return FlutterResponse::success([
            'connections' => $connections
                ->map(fn (FlutterConnection $connection) => $connection->toBridgeArray($currentId))
                ->values()
                ->all(),
        ], 'Connections retrieved');
    }

    /**
     * Revoke one of the authenticated user's sessions.
     */
    public function revoke(Request $request, string $connection): JsonResponse
    {
        $this->ensureEnabled();

        $model = $this->connections->find($connection);

        if ($model === null || !$model->belongsToUser($this->userId($request))) {
            throw FlutterBridgeException::connectionNotFound();
        }

        $model = $this->connections->revoke($model, 'revoked_by_user');

        return FlutterResponse::success([
            'connection' => $model->toBridgeArray(BridgeRequest::connectionId($request)),
        ], 'Connection revoked');
    }

    /**
     * Revoke every session of the authenticated user except the current one.
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $revoked = $this->connections->revokeAllForUser(
            $this->userId($request),
            BridgeRequest::connectionId($request),
            'revoked_by_user'
        );

        return FlutterResponse::success([
            'revoked' => $revoked,
        ], 'Other connections revoked');
    }

    /**
     * Find a session the caller may operate on. A session owned by a user is
     * hidden from a different authenticated user.
     *
     * @throws FlutterBridgeException
     */
    protected function findAccessible(string $id, ?Authenticatable $user): FlutterConnection
    {
        $connection = $this->connections->find($id);

        if ($connection === null) {
            throw FlutterBridgeException::connectionNotFound();
        }

        if ($user !== null && $connection->user_id !== null && !$connection->belongsToUser($user->getAuthIdentifier())) {
            throw FlutterBridgeException::connectionNotFound();
        }

        return $connection;
    }

    /**
     * The id considered "current" for is_current on per-session routes.
     */
    protected function currentId(Request $request, FlutterConnection $connection): string
    {
        return BridgeRequest::connectionId($request) ?? (string) $connection->getKey();
    }

    /**
     * The authenticated user's id (bearer routes).
     */
    protected function userId(Request $request): int|string
    {
        $user = $request->user();

        if (!$user instanceof Authenticatable) {
            throw new FlutterBridgeException('Unauthenticated.', 401, 'unauthenticated');
        }

        return $user->getAuthIdentifier();
    }

    /**
     * Configured heartbeat interval in seconds.
     */
    protected function heartbeatInterval(): int
    {
        return (int) config('flutter-bridge.connections.heartbeat_interval', 30);
    }

    /**
     * Abort with 403 feature_disabled when connections are turned off.
     *
     * @throws FlutterBridgeException
     */
    protected function ensureEnabled(): void
    {
        if (!config('flutter-bridge.connections.enabled', true)) {
            throw FlutterBridgeException::featureDisabled('connections');
        }
    }
}
