<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Facades\FlutterBridge;
use Atannex\LaravelFlutterBridge\Tests\TestCase;

class HandshakeTest extends TestCase
{
    public function test_handshake_shape(): void
    {
        $response = $this->bridge('GET', 'handshake');

        $response->assertOk()
            ->assertHeader('X-Laravel-Flutter-Bridge', '2.0.0')
            ->assertHeader('X-Flutter-Server-Time')
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'server' => ['name', 'environment', 'url', 'timezone', 'locale', 'time'],
                    'bridge' => ['version', 'api_version', 'prefix'],
                    'app',
                    'features' => ['auth', 'registration', 'social_auth', 'two_factor', 'devices', 'broadcasting', 'connections', 'push'],
                    'endpoints',
                    'broadcasting',
                    'connection' => ['heartbeat_interval', 'timeout'],
                    'remote_config',
                ],
                'meta' => ['timestamp', 'api_version', 'trace_id'],
            ])
            ->assertJsonPath('data.server.name', 'Bridge Test')
            ->assertJsonPath('data.bridge.version', '2.0.0')
            ->assertJsonPath('data.bridge.prefix', 'api/flutter')
            ->assertJsonPath('data.app', null)
            ->assertJsonPath('data.broadcasting', null)
            ->assertJsonPath('data.connection.heartbeat_interval', 30)
            ->assertJsonPath('data.connection.timeout', 90);

        $this->assertStringContainsString('"remote_config":{}', $response->getContent());
    }

    public function test_endpoints_map_matches_the_contract(): void
    {
        $endpoints = $this->bridge('GET', 'handshake')->json('data.endpoints');

        $expected = [
            'ping' => 'api/flutter/ping',
            'handshake' => 'api/flutter/handshake',
            'config' => 'api/flutter/config',
            'auth.login' => 'api/flutter/auth/login',
            'auth.register' => 'api/flutter/auth/register',
            'auth.social' => 'api/flutter/auth/social',
            'auth.refresh' => 'api/flutter/auth/refresh',
            'auth.2fa.send' => 'api/flutter/auth/2fa/send-otp',
            'auth.2fa.verify' => 'api/flutter/auth/2fa/verify',
            'auth.me' => 'api/flutter/auth/me',
            'auth.logout' => 'api/flutter/auth/logout',
            'devices.register' => 'api/flutter/devices/register',
            'devices.unregister' => 'api/flutter/devices/{device}',
            'broadcasting.auth' => 'api/flutter/broadcasting/auth',
            'connections.connect' => 'api/flutter/connections/connect',
            'connections.heartbeat' => 'api/flutter/connections/{connection}/heartbeat',
            'connections.disconnect' => 'api/flutter/connections/{connection}/disconnect',
            'connections.index' => 'api/flutter/connections',
            'connections.revoke' => 'api/flutter/connections/{connection}',
            'connections.revoke_others' => 'api/flutter/connections',
        ];

        ksort($expected);
        $this->assertSame($expected, $endpoints);
    }

    public function test_handshake_includes_app_and_merged_remote_config(): void
    {
        config()->set('flutter-bridge.remote_config', ['theme' => 'dark', 'limits' => ['upload' => 10]]);

        $app = FlutterBridge::apps()->create('Acme iOS', [
            'platforms' => 'ios,android',
            'min_version' => '1.2.0',
            'latest_version' => '1.5.0',
            'update_url' => 'https://example.test/update',
            'settings' => ['limits' => ['upload' => 50]],
        ]);

        $this->bridge('GET', 'handshake', [], ['X-Flutter-App-Key' => $app->key])
            ->assertOk()
            ->assertJsonPath('data.app.key', $app->key)
            ->assertJsonPath('data.app.name', 'Acme iOS')
            ->assertJsonPath('data.app.status', 'active')
            ->assertJsonPath('data.app.min_version', '1.2.0')
            ->assertJsonPath('data.app.latest_version', '1.5.0')
            ->assertJsonPath('data.app.platforms', ['ios', 'android'])
            ->assertJsonPath('data.remote_config.theme', 'dark')
            ->assertJsonPath('data.remote_config.limits.upload', 50);

        $this->bridge('GET', 'config', [], ['X-Flutter-App-Key' => $app->key])
            ->assertOk()
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.limits.upload', 50);

        $this->assertNotNull($app->fresh()->last_connected_at);
    }

    public function test_handshake_advertises_reverb_broadcasting(): void
    {
        config()->set('broadcasting.connections.reverb', [
            'driver' => 'reverb',
            'key' => 'reverb-key',
            'secret' => 'secret',
            'app_id' => '1',
            'options' => ['host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https'],
        ]);
        config()->set('broadcasting.default', 'reverb');

        $this->bridge('GET', 'handshake')
            ->assertOk()
            ->assertJsonPath('data.broadcasting.driver', 'reverb')
            ->assertJsonPath('data.broadcasting.key', 'reverb-key')
            ->assertJsonPath('data.broadcasting.host', 'ws.example.test')
            ->assertJsonPath('data.broadcasting.port', 443)
            ->assertJsonPath('data.broadcasting.scheme', 'https')
            ->assertJsonPath('data.broadcasting.cluster', null)
            ->assertJsonPath('data.broadcasting.auth_endpoint', 'api/flutter/broadcasting/auth')
            ->assertJsonPath('data.broadcasting.connection_channel', 'private-flutter.connections.{connection}')
            ->assertJsonPath('data.broadcasting.user_channel', 'private-users.{user}');
    }

    public function test_ping(): void
    {
        $this->bridge('GET', 'ping')
            ->assertOk()
            ->assertJsonPath('data.pong', true)
            ->assertJsonStructure(['data' => ['pong', 'server_time']]);
    }

    public function test_app_key_required(): void
    {
        config()->set('flutter-bridge.connections.require_app_key', true);

        $this->bridge('GET', 'ping')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.error_code', 'app_key_required');
    }

    public function test_invalid_app_key(): void
    {
        $this->bridge('GET', 'handshake', [], ['X-Flutter-App-Key' => 'fba_unknown'])
            ->assertStatus(403)
            ->assertJsonPath('meta.error_code', 'invalid_app_key');
    }

    public function test_suspended_app(): void
    {
        $app = FlutterBridge::apps()->create('Acme');
        FlutterBridge::apps()->suspend($app);

        $this->bridge('GET', 'handshake', [], ['X-Flutter-App-Key' => $app->key])
            ->assertStatus(403)
            ->assertJsonPath('meta.error_code', 'app_suspended');
    }

    public function test_upgrade_required_with_handshake_and_ping_exempt(): void
    {
        $app = FlutterBridge::apps()->create('Acme', [
            'min_version' => '1.2.0',
            'latest_version' => '1.5.0',
            'update_url' => 'https://example.test/update',
        ]);

        $headers = ['X-Flutter-App-Key' => $app->key, 'X-Flutter-App-Version' => '1.1.9'];

        $this->bridge('POST', 'connections/connect', ['device_id' => 'd', 'platform' => 'ios'], $headers)
            ->assertStatus(426)
            ->assertJsonPath('meta.error_code', 'upgrade_required')
            ->assertJsonPath('errors.current_version', '1.1.9')
            ->assertJsonPath('errors.min_version', '1.2.0')
            ->assertJsonPath('errors.latest_version', '1.5.0')
            ->assertJsonPath('errors.update_url', 'https://example.test/update');

        $this->bridge('GET', 'handshake', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.app.update_url', 'https://example.test/update');

        $this->bridge('GET', 'ping', [], $headers)->assertOk();

        $this->bridge('POST', 'connections/connect', ['device_id' => 'd', 'platform' => 'ios'], array_merge($headers, ['X-Flutter-App-Version' => '1.2.0']))
            ->assertStatus(201);
    }

    public function test_global_min_version_applies_without_app(): void
    {
        config()->set('flutter-bridge.connections.min_app_version', '2.0.0');

        $this->bridge('GET', 'config', [], ['X-Flutter-App-Version' => '1.9.9'])
            ->assertStatus(426)
            ->assertJsonPath('errors.min_version', '2.0.0');

        // Skipped when the header is absent.
        $this->bridge('GET', 'config')->assertOk();
    }

    public function test_validation_failed_envelope(): void
    {
        $this->bridge('POST', 'connections/connect', ['platform' => 'symbian'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.error_code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['device_id', 'platform']]);
    }

    public function test_unauthenticated_envelope(): void
    {
        $this->bridge('GET', 'auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.error_code', 'unauthenticated')
            ->assertHeader('X-Laravel-Flutter-Bridge', '2.0.0');
    }

    public function test_feature_toggle_disables_registration(): void
    {
        config()->set('flutter-bridge.features.registration', false);

        $this->bridge('POST', 'auth/register', [
            'name' => 'A', 'email' => 'a@example.test', 'password' => 'password123',
        ])->assertStatus(403)->assertJsonPath('meta.error_code', 'feature_disabled');

        $this->bridge('GET', 'handshake')->assertJsonPath('data.features.registration', false);
    }
}
