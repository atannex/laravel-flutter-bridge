<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Facades\FlutterBridge;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\RecordingBroadcaster;
use Atannex\LaravelFlutterBridge\Tests\TestCase;
use Illuminate\Broadcasting\BroadcastManager;

class BroadcastingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('broadcasting.connections.recording', ['driver' => 'recording']);
        $app['config']->set('broadcasting.default', 'recording');

        $app->resolving(BroadcastManager::class, function (BroadcastManager $manager) {
            $manager->extend('recording', fn () => new RecordingBroadcaster());
        });

        RecordingBroadcaster::$broadcasts = [];
    }

    public function test_broadcasting_auth_is_enveloped(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('t')->plainTextToken;
        $connection = $this->connectDevice('phone', ['Authorization' => "Bearer {$token}"]);

        $response = $this->bridge('POST', 'broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-flutter.connections.{$connection}",
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.channel_data', null);
        $this->assertStringStartsWith('test-key:', (string) $response->json('data.auth'));

        $this->bridge('POST', 'broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-users.{$user->id}",
        ], ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    public function test_broadcasting_auth_rejects_foreign_channels(): void
    {
        $user = $this->createUser();
        $other = $this->createUser('other@example.test');
        $connection = $this->connectDevice('phone', ['Authorization' => 'Bearer ' . $other->createToken('t')->plainTextToken]);
        $token = $user->createToken('t')->plainTextToken;

        $this->bridge('POST', 'broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-flutter.connections.{$connection}",
        ], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.error_code', 'forbidden');

        $this->bridge('POST', 'broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-users.{$other->id}",
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(403);
    }

    public function test_commands_and_revocations_are_broadcast(): void
    {
        $id = $this->connectDevice('phone');

        $command = FlutterBridge::connections()->sendCommand($id, 'message', ['title' => 'Hi']);
        FlutterBridge::connections()->revoke($id, 'admin');

        $events = collect(RecordingBroadcaster::$broadcasts);

        $bridgeCommand = $events->firstWhere('event', 'BridgeCommand');
        $this->assertNotNull($bridgeCommand);
        $this->assertSame(["private-flutter.connections.{$id}"], $bridgeCommand['channels']);
        $this->assertSame((string) $command->id, $bridgeCommand['payload']['id']);
        $this->assertSame('message', $bridgeCommand['payload']['type']);
        $this->assertSame(['title' => 'Hi'], $bridgeCommand['payload']['payload']);

        $revoked = $events->firstWhere('event', 'ConnectionRevoked');
        $this->assertNotNull($revoked);
        $this->assertSame(["private-flutter.connections.{$id}"], $revoked['channels']);
        $this->assertSame('admin', $revoked['payload']['reason']);

        $this->assertSame(2, $events->where('event', 'BridgeCommand')->count());
    }

    public function test_nothing_is_broadcast_when_disabled(): void
    {
        config()->set('flutter-bridge.broadcasting.enabled', false);
        $id = $this->connectDevice('phone');

        FlutterBridge::connections()->sendCommand($id, 'message');

        $this->assertSame([], RecordingBroadcaster::$broadcasts);
    }
}
