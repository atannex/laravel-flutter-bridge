<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Middleware;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards and tracks the session named by X-Flutter-Connection-Id (alias: flutter.connection).
 *
 * Revoked sessions receive 403 connection_revoked; known sessions have their
 * last_seen_at touched (throttled). Unknown ids are ignored here.
 */
class TrackFlutterConnection
{
    public function __construct(
        protected FlutterConnectionManager $connections
    ) {}

    /**
     * Handle an incoming request.
     *
     * @throws FlutterBridgeException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = BridgeRequest::connectionId($request);

        if ($id === null || ! config('flutter-bridge.connections.enabled', true)) {
            return $next($request);
        }

        $connection = $this->connections->find($id);

        if ($connection instanceof FlutterConnection) {
            if ($connection->isRevoked()) {
                throw FlutterBridgeException::connectionRevoked();
            }

            $this->connections->touch(
                $connection,
                (int) config('flutter-bridge.connections.touch_throttle', 10)
            );

            $request->attributes->set(BridgeRequest::ATTR_CONNECTION, $connection);
        }

        return $next($request);
    }
}
