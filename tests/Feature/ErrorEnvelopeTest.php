<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Tests\TestCase;
use RuntimeException;

class ErrorEnvelopeTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware(['flutter.headers', 'flutter.response', 'throttle:1,1'])
            ->get('api/flutter/_test/throttled', fn () => ['ok' => true]);

        $router->middleware(['flutter.headers', 'flutter.response'])
            ->get('api/flutter/_test/plain', fn () => response()->json(['value' => 1]));

        $router->middleware(['flutter.headers', 'flutter.response'])
            ->get('api/flutter/_test/explode', fn () => throw new RuntimeException('boom'));
    }

    public function test_throttle_is_enveloped_with_retry_after(): void
    {
        $this->getJson('/api/flutter/_test/throttled')->assertOk()->assertJsonPath('data.ok', true);

        $this->getJson('/api/flutter/_test/throttled')
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.error_code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }

    public function test_plain_json_is_wrapped(): void
    {
        $this->getJson('/api/flutter/_test/plain')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.value', 1);
    }

    public function test_server_errors_are_enveloped(): void
    {
        config()->set('app.debug', false);

        $this->getJson('/api/flutter/_test/explode')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.error_code', 'server_error');
    }

    public function test_maintenance_mode_is_enveloped(): void
    {
        $this->app->maintenanceMode()->activate([]);

        try {
            $this->getJson('/api/flutter/ping')
                ->assertStatus(503)
                ->assertJsonPath('success', false)
                ->assertJsonPath('meta.error_code', 'maintenance')
                ->assertHeader('X-Laravel-Flutter-Bridge', '2.0.0');
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_unknown_bridge_route_is_enveloped(): void
    {
        $this->getJson('/api/flutter/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('meta.error_code', 'not_found');
    }
}
