<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Events\FlutterCommandQueued;
use Atannex\LaravelFlutterBridge\Events\FlutterConnectionDisconnected;
use Atannex\LaravelFlutterBridge\Events\FlutterConnectionEstablished;
use Atannex\LaravelFlutterBridge\Events\FlutterConnectionRevoked;
use Atannex\LaravelFlutterBridge\Facades\FlutterBridge;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Atannex\LaravelFlutterBridge\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;

class ConnectionTest extends TestCase
{
    public function test_connect_creates_new_session_with_contract_shape(): void
    {
        Event::fake([FlutterConnectionEstablished::class]);
        $app = FlutterBridge::apps()->create('Acme');

        $response = $this->bridge('POST', 'connections/connect', [
            'device_id' => 'abc',
            'platform' => 'android',
            'app_version' => '1.4.2',
            'device_name' => 'Pixel 9',
            'os_version' => '15',
            'fcm_token' => 'fcm-1',
            'metadata' => ['locale' => 'en'],
        ], ['X-Flutter-App-Key' => $app->key, 'User-Agent' => 'FlutterTest/1.0']);

        $response->assertStatus(201)
            ->assertJsonPath('data.heartbeat_interval', 30)
            ->assertJsonPath('data.timeout', 90)
            ->assertJsonPath('data.connection.app_key', $app->key)
            ->assertJsonPath('data.connection.user_id', null)
            ->assertJsonPath('data.connection.device_id', 'abc')
            ->assertJsonPath('data.connection.platform', 'android')
            ->assertJsonPath('data.connection.device_name', 'Pixel 9')
            ->assertJsonPath('data.connection.app_version', '1.4.2')
            ->assertJsonPath('data.connection.os_version', '15')
            ->assertJsonPath('data.connection.user_agent', 'FlutterTest/1.0')
            ->assertJsonPath('data.connection.status', 'active')
            ->assertJsonPath('data.connection.is_online', true)
            ->assertJsonPath('data.connection.is_current', true)
            ->assertJsonPath('data.connection.disconnected_at', null)
            ->assertJsonPath('data.connection.revoked_at', null)
            ->assertJsonPath('data.connection.metadata.locale', 'en');

        $this->assertSame(
            ['id', 'app_key', 'user_id', 'device_id', 'platform', 'device_name', 'app_version', 'os_version',
                'ip_address', 'user_agent', 'status', 'is_online', 'is_current', 'connected_at', 'last_seen_at',
                'disconnected_at', 'revoked_at', 'metadata'],
            array_keys($response->json('data.connection'))
        );
        $this->assertTrue(\Illuminate\Support\Str::isUuid($response->json('data.connection.id')));
        $this->assertNotNull($response->json('data.server_time'));

        $device = FlutterDevice::query()->where('device_id', 'abc')->first();
        $this->assertNotNull($device);
        $this->assertSame('fcm-1', $device->fcm_token);

        Event::assertDispatched(FlutterConnectionEstablished::class, fn ($e) => $e->resumed === false);
    }

    public function test_connect_resumes_existing_session(): void
    {
        $first = $this->connectDevice('abc');
        FlutterBridge::connections()->disconnect($first);
        $connectedAt = FlutterConnection::query()->find($first)->connected_at;

        $this->travel(5)->minutes();

        $response = $this->bridge('POST', 'connections/connect', [
            'device_id' => 'abc',
            'platform' => 'android',
            'app_version' => '1.5.0',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.connection.id', $first)
            ->assertJsonPath('data.connection.status', 'active')
            ->assertJsonPath('data.connection.app_version', '1.5.0')
            ->assertJsonPath('data.connection.disconnected_at', null);

        $this->assertTrue(FlutterConnection::query()->find($first)->connected_at->equalTo($connectedAt));
        $this->assertSame(1, FlutterConnection::query()->count());
    }

    public function test_connect_does_not_resume_revoked_or_other_apps_sessions(): void
    {
        $first = $this->connectDevice('abc');
        FlutterBridge::connections()->revoke($first);

        $second = $this->connectDevice('abc');
        $this->assertNotSame($first, $second);

        $app = FlutterBridge::apps()->create('Other');
        $third = $this->connectDevice('abc', ['X-Flutter-App-Key' => $app->key]);
        $this->assertNotSame($second, $third);
    }

    public function test_anonymous_connect_does_not_resume_a_users_session(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('t')->plainTextToken;

        $owned = $this->connectDevice('abc', ['Authorization' => "Bearer {$token}"]);
        $this->assertSame($user->id, FlutterConnection::query()->find($owned)->user_id);

        $anonymous = $this->connectDevice('abc');
        $this->assertNotSame($owned, $anonymous);
    }

    public function test_heartbeat_delivers_commands_exactly_once(): void
    {
        $id = $this->connectDevice();

        FlutterBridge::connections()->sendCommand($id, 'message', ['title' => 'Hi']);
        FlutterBridge::connections()->sendCommand($id, 'refresh_config');
        $expired = FlutterBridge::connections()->sendCommand($id, 'stale', [], 1);
        $this->travel(2)->minutes();

        $response = $this->bridge('POST', "connections/{$id}/heartbeat", ['state' => 'background'], ['X-Flutter-Connection-Id' => $id]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.commands')
            ->assertJsonPath('data.commands.0.type', 'message')
            ->assertJsonPath('data.commands.0.payload.title', 'Hi')
            ->assertJsonPath('data.commands.1.type', 'refresh_config')
            ->assertJsonPath('data.connection.is_current', true)
            ->assertJsonPath('data.heartbeat_interval', 30)
            ->assertJsonStructure(['data' => ['connection', 'commands' => [['id', 'type', 'payload', 'created_at']], 'server_time', 'heartbeat_interval']]);

        $this->assertIsString($response->json('data.commands.0.id'));
        $this->assertStringContainsString('"payload":{}', $response->getContent());
        $this->assertSame('background', FlutterConnection::query()->find($id)->state);
        $this->assertNull($expired->fresh()->delivered_at);

        $this->bridge('POST', "connections/{$id}/heartbeat")
            ->assertOk()
            ->assertJsonCount(0, 'data.commands');
    }

    public function test_heartbeat_reactivates_and_links_user(): void
    {
        $id = $this->connectDevice();
        FlutterBridge::connections()->disconnect($id);

        $user = $this->createUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->bridge('POST', "connections/{$id}/heartbeat", [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.connection.status', 'active')
            ->assertJsonPath('data.connection.user_id', $user->id);
    }

    public function test_heartbeat_unknown_connection(): void
    {
        $this->bridge('POST', 'connections/00000000-0000-0000-0000-000000000000/heartbeat')
            ->assertStatus(404)
            ->assertJsonPath('meta.error_code', 'connection_not_found');
    }

    public function test_heartbeat_hidden_from_another_user(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');

        $id = $this->connectDevice('abc', ['Authorization' => 'Bearer ' . $owner->createToken('t')->plainTextToken]);

        $this->bridge('POST', "connections/{$id}/heartbeat", [], ['Authorization' => 'Bearer ' . $other->createToken('t')->plainTextToken])
            ->assertStatus(404)
            ->assertJsonPath('meta.error_code', 'connection_not_found');
    }

    public function test_revoked_connection_gets_403_on_heartbeat_and_any_request(): void
    {
        $id = $this->connectDevice();
        FlutterBridge::connections()->revoke($id, 'stolen');

        $this->bridge('POST', "connections/{$id}/heartbeat")
            ->assertStatus(403)
            ->assertJsonPath('meta.error_code', 'connection_revoked');

        $this->bridge('GET', 'config', [], ['X-Flutter-Connection-Id' => $id])
            ->assertStatus(403)
            ->assertJsonPath('meta.error_code', 'connection_revoked');

        // Unknown ids in the header are ignored by middleware.
        $this->bridge('GET', 'config', [], ['X-Flutter-Connection-Id' => 'unknown'])->assertOk();
    }

    public function test_connection_middleware_touches_last_seen_throttled(): void
    {
        $id = $this->connectDevice();
        $initial = FlutterConnection::query()->find($id)->last_seen_at;

        $this->travel(5)->seconds();
        $this->bridge('GET', 'config', [], ['X-Flutter-Connection-Id' => $id])->assertOk();
        $this->assertTrue(FlutterConnection::query()->find($id)->last_seen_at->equalTo($initial));

        $this->travel(10)->seconds();
        $this->bridge('GET', 'config', [], ['X-Flutter-Connection-Id' => $id])->assertOk();
        $this->assertTrue(FlutterConnection::query()->find($id)->last_seen_at->greaterThan($initial));
    }

    public function test_disconnect(): void
    {
        Event::fake([FlutterConnectionDisconnected::class]);
        $id = $this->connectDevice();

        $this->bridge('POST', "connections/{$id}/disconnect")
            ->assertOk()
            ->assertJsonPath('data.connection.status', 'disconnected')
            ->assertJsonPath('data.connection.is_online', false);

        $this->assertNotNull(FlutterConnection::query()->find($id)->disconnected_at);
        Event::assertDispatched(FlutterConnectionDisconnected::class);
    }

    public function test_is_online_respects_timeout(): void
    {
        $id = $this->connectDevice();
        config()->set('flutter-bridge.connections.timeout', 60);

        $this->assertTrue(FlutterConnection::query()->find($id)->isOnline());
        $this->travel(61)->seconds();
        $this->assertFalse(FlutterConnection::query()->find($id)->isOnline());
        $this->assertFalse(FlutterBridge::connections()->isUserOnline(1));
    }

    public function test_list_revoke_and_revoke_others(): void
    {
        Event::fake([FlutterConnectionRevoked::class, FlutterCommandQueued::class]);

        $user = $this->createUser();
        $other = $this->createUser('other@example.test');

        $current = $this->connectDevice('phone');
        $data = $this->login(headers: ['X-Flutter-Connection-Id' => $current]);
        $token = $data['authorization']['access_token'];
        $auth = ['Authorization' => "Bearer {$token}", 'X-Flutter-Connection-Id' => $current];

        $tablet = $this->connectDevice('tablet', ['Authorization' => "Bearer {$token}"]);
        $laptop = $this->connectDevice('laptop', ['Authorization' => "Bearer {$token}"]);
        $foreign = $this->connectDevice('foreign', ['Authorization' => 'Bearer ' . $other->createToken('t')->plainTextToken]);

        $list = $this->bridge('GET', 'connections', [], $auth)->assertOk();
        $this->assertCount(3, $list->json('data.connections'));
        $currentRow = collect($list->json('data.connections'))->firstWhere('id', $current);
        $this->assertTrue($currentRow['is_current']);
        $this->assertSame($user->id, $currentRow['user_id']);

        // Cannot revoke another user's session.
        $this->bridge('DELETE', "connections/{$foreign}", [], $auth)
            ->assertStatus(404)
            ->assertJsonPath('meta.error_code', 'connection_not_found');
        $this->assertSame('active', FlutterConnection::query()->find($foreign)->status);

        $this->bridge('DELETE', "connections/{$tablet}", [], $auth)
            ->assertOk()
            ->assertJsonPath('data.connection.status', 'revoked')
            ->assertJsonPath('data.connection.id', $tablet);

        $this->assertSame(1, FlutterBridge::connections()->pendingCommandsFor($tablet)->where('type', 'revoked')->count());
        Event::assertDispatched(FlutterConnectionRevoked::class, fn ($e) => $e->connection->id === $tablet);
        Event::assertDispatched(FlutterCommandQueued::class, fn ($e) => $e->command->type === 'revoked');

        $this->bridge('GET', 'connections?status=active', [], $auth)
            ->assertOk()
            ->assertJsonCount(2, 'data.connections');

        $this->bridge('DELETE', 'connections', [], $auth)
            ->assertOk()
            ->assertJsonPath('data.revoked', 1);

        $this->assertSame('revoked', FlutterConnection::query()->find($laptop)->status);
        $this->assertSame('active', FlutterConnection::query()->find($current)->status);
        $this->assertSame('active', FlutterConnection::query()->find($foreign)->status);
    }

    public function test_revoking_a_session_revokes_its_tokens(): void
    {
        $this->createUser();
        $id = $this->connectDevice('phone');
        $data = $this->login(headers: ['X-Flutter-Connection-Id' => $id]);

        $this->assertSame(1, PersonalAccessToken::query()->count());

        FlutterBridge::connections()->revoke($id);

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertNotNull(FlutterRefreshToken::query()->first()->revoked_at);

        $this->bridge('GET', 'auth/me', [], ['Authorization' => 'Bearer ' . $data['authorization']['access_token']])
            ->assertStatus(401)
            ->assertJsonPath('meta.error_code', 'unauthenticated');

        $this->bridge('POST', 'auth/refresh', ['refresh_token' => $data['authorization']['refresh_token']])
            ->assertStatus(401)
            ->assertJsonPath('meta.error_code', 'invalid_refresh_token');
    }

    public function test_revoked_session_wins_over_unauthenticated_on_bearer_routes(): void
    {
        $this->createUser();
        $id = $this->connectDevice('phone');
        $token = $this->login(headers: ['X-Flutter-Connection-Id' => $id])['authorization']['access_token'];
        FlutterBridge::connections()->revoke($id);

        $this->bridge('GET', 'connections', [], ['Authorization' => "Bearer {$token}", 'X-Flutter-Connection-Id' => $id])
            ->assertStatus(403)
            ->assertJsonPath('meta.error_code', 'connection_revoked');
    }

    public function test_bearer_routes_require_authentication(): void
    {
        $this->bridge('GET', 'connections')->assertStatus(401)->assertJsonPath('meta.error_code', 'unauthenticated');
        $this->bridge('DELETE', 'connections')->assertStatus(401);
    }
}
