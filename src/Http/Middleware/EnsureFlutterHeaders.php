<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Middleware;

use Closure;
use Atannex\LaravelFlutterBridge\Services\FlutterBridgeManager;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFlutterHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Enforce application/json expectation for all Flutter requests
        $request->headers->set('Accept', 'application/json');

        // Extract and record client telemetry headers
        $request->attributes->set('flutter_platform', $request->header('X-Flutter-Platform', 'unknown'));
        $request->attributes->set('flutter_app_version', $request->header('X-Flutter-App-Version'));
        $request->attributes->set('flutter_device_id', $request->header('X-Flutter-Device-Id'));
        $request->attributes->set(BridgeRequest::ATTR_CONNECTION_ID, $this->header($request, 'X-Flutter-Connection-Id'));
        $request->attributes->set(BridgeRequest::ATTR_APP_KEY, $this->header($request, 'X-Flutter-App-Key'));
        $request->attributes->set(BridgeRequest::ATTR_BRIDGE_VERSION, $this->header($request, 'X-Flutter-Bridge-Version'));

        /** @var Response $response */
        $response = $next($request);

        return self::decorate($response);
    }

    /**
     * Append the server bridge headers to a response.
     */
    public static function decorate(Response $response): Response
    {
        $response->headers->set('X-Laravel-Flutter-Bridge', FlutterBridgeManager::VERSION);
        $response->headers->set('X-Flutter-Server-Time', now()->toIso8601String());

        return $response;
    }

    /**
     * Read a trimmed, non-empty header value.
     */
    protected function header(Request $request, string $name): ?string
    {
        $value = $request->header($name);

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
