<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Middleware;

use Closure;
use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Services\FlutterAppManager;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the client app from X-Flutter-App-Key (alias: flutter.app).
 */
class ResolveFlutterApp
{
    public function __construct(
        protected FlutterAppManager $apps
    ) {}

    /**
     * Handle an incoming request.
     *
     * @throws FlutterBridgeException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get(BridgeRequest::ATTR_APP_KEY) ?? $request->header('X-Flutter-App-Key');
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            if (config('flutter-bridge.connections.require_app_key', false)) {
                throw FlutterBridgeException::appKeyRequired();
            }

            return $next($request);
        }

        $app = $this->apps->findByKey($key);

        if ($app === null) {
            throw FlutterBridgeException::invalidAppKey();
        }

        if ($app->isSuspended()) {
            throw FlutterBridgeException::appSuspended();
        }

        $request->attributes->set(BridgeRequest::ATTR_APP, $app);
        $this->apps->touch($app);

        return $next($request);
    }
}
