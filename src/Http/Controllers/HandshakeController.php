<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers;

use Atannex\LaravelFlutterBridge\Broadcasting\BroadcastGate;
use Atannex\LaravelFlutterBridge\Models\FlutterApp;
use Atannex\LaravelFlutterBridge\Services\FlutterBridgeManager;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Atannex\LaravelFlutterBridge\Support\Payload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

class HandshakeController extends Controller
{
    public function __construct(
        protected Router $router
    ) {}

    /**
     * Describe the server, bridge, app policy, features, endpoints and realtime setup.
     */
    public function handshake(Request $request): JsonResponse
    {
        /** @var FlutterBridgeManager $bridge */
        $bridge = app('flutter.bridge');
        $app = BridgeRequest::app($request);
        $endpoints = $this->endpoints();

        return FlutterResponse::success([
            'server' => [
                'name' => (string) config('app.name', 'Laravel'),
                'environment' => (string) app()->environment(),
                'url' => (string) config('app.url', $request->getSchemeAndHttpHost()),
                'timezone' => (string) config('app.timezone', 'UTC'),
                'locale' => (string) app()->getLocale(),
                'time' => now()->toIso8601String(),
            ],
            'bridge' => [
                'version' => FlutterBridgeManager::VERSION,
                'api_version' => (string) config('flutter-bridge.envelope.api_version', 'v1'),
                'prefix' => $this->prefix(),
            ],
            'app' => $app?->toBridgeArray(),
            'features' => $bridge->features(),
            'endpoints' => Payload::object($endpoints),
            'broadcasting' => $this->broadcasting($endpoints),
            'connection' => [
                'heartbeat_interval' => (int) config('flutter-bridge.connections.heartbeat_interval', 30),
                'timeout' => (int) config('flutter-bridge.connections.timeout', 90),
            ],
            'remote_config' => Payload::object($this->remoteConfig($app)),
        ], 'Handshake successful');
    }

    /**
     * Latency probe.
     */
    public function ping(): JsonResponse
    {
        return FlutterResponse::success([
            'pong' => true,
            'server_time' => now()->toIso8601String(),
        ], 'pong');
    }

    /**
     * Remote config (server config merged with the app's settings).
     */
    public function config(Request $request): JsonResponse
    {
        return FlutterResponse::success(
            Payload::object($this->remoteConfig(BridgeRequest::app($request))),
            'Remote config retrieved'
        );
    }

    /**
     * Map of endpoint keys to URIs generated from the registered bridge routes.
     *
     * @return array<string, string>
     */
    protected function endpoints(): array
    {
        $prefix = $this->prefix();
        $endpoints = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            $uri = ltrim($route->uri(), '/');

            if ($name === null || ! str_starts_with($name, 'flutter.')) {
                continue;
            }

            if ($prefix !== '' && $uri !== $prefix && ! Str::startsWith($uri, $prefix.'/')) {
                continue;
            }

            $endpoints[substr($name, strlen('flutter.'))] = $uri;
        }

        ksort($endpoints);

        return $endpoints;
    }

    /**
     * Broadcasting client options, or null when not advertisable.
     *
     * @param  array<string, string>  $endpoints
     * @return array<string, mixed>|null
     */
    protected function broadcasting(array $endpoints): ?array
    {
        $options = BroadcastGate::clientOptions();

        if ($options === null) {
            return null;
        }

        return array_merge($options, [
            'auth_endpoint' => $endpoints['broadcasting.auth']
                ?? ltrim($this->prefix().'/'.ltrim((string) config('flutter-bridge.broadcasting.auth_path', 'broadcasting/auth'), '/'), '/'),
            'connection_channel' => 'private-flutter.connections.{connection}',
            'user_channel' => 'private-users.{user}',
        ]);
    }

    /**
     * Server remote config merged with the app's own settings.
     *
     * @return array<string, mixed>
     */
    protected function remoteConfig(?FlutterApp $app): array
    {
        return array_replace_recursive(
            (array) config('flutter-bridge.remote_config', []),
            $app->settings ?? []
        );
    }

    /**
     * Normalized route prefix.
     */
    protected function prefix(): string
    {
        return trim((string) config('flutter-bridge.prefix', 'api/flutter'), '/');
    }
}
