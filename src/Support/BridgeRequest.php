<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Support;

use Atannex\LaravelFlutterBridge\Models\FlutterApp;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Request-level helpers shared by bridge middleware and controllers.
 */
final class BridgeRequest
{
    public const ATTR_APP = 'flutter_app';

    public const ATTR_CONNECTION = 'flutter_connection';

    public const ATTR_CONNECTION_ID = 'flutter_connection_id';

    public const ATTR_APP_KEY = 'flutter_app_key';

    public const ATTR_BRIDGE_VERSION = 'flutter_bridge_version';

    /**
     * Whether the request targets the bridge route prefix.
     */
    public static function isBridgeRequest(Request $request): bool
    {
        $prefix = trim((string) config('flutter-bridge.prefix', 'api/flutter'), '/');

        return $prefix === '' || $request->is($prefix) || $request->is($prefix.'/*');
    }

    /**
     * Resolve the user from the request or the configured guard (optional bearer).
     */
    public static function user(Request $request): ?Authenticatable
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            return $user;
        }

        $guard = (string) config('flutter-bridge.auth.guard', 'sanctum');

        try {
            $user = auth()->guard($guard)->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * The X-Flutter-Connection-Id header value.
     */
    public static function connectionId(Request $request): ?string
    {
        $id = $request->attributes->get(self::ATTR_CONNECTION_ID) ?? $request->header('X-Flutter-Connection-Id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The client app resolved by the flutter.app middleware.
     */
    public static function app(Request $request): ?FlutterApp
    {
        $app = $request->attributes->get(self::ATTR_APP);

        return $app instanceof FlutterApp ? $app : null;
    }

    /**
     * The (non-revoked) connection resolved by the flutter.connection middleware,
     * or looked up from the header when the middleware did not run.
     */
    public static function connection(Request $request): ?FlutterConnection
    {
        $connection = $request->attributes->get(self::ATTR_CONNECTION);

        if ($connection instanceof FlutterConnection) {
            return $connection;
        }

        $id = self::connectionId($request);

        if ($id === null || ! config('flutter-bridge.connections.enabled', true)) {
            return null;
        }

        $connection = FlutterConnection::query()->find($id);

        return $connection instanceof FlutterConnection && ! $connection->isRevoked() ? $connection : null;
    }
}
