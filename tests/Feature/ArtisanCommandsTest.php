<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Facades\FlutterBridge;
use Atannex\LaravelFlutterBridge\Models\FlutterApp;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterConnectionCommand;
use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Atannex\LaravelFlutterBridge\Tests\TestCase;
use Illuminate\Support\Facades\File;

class ArtisanCommandsTest extends TestCase
{
    public function test_app_create_prints_key_once(): void
    {
        $this->artisan('flutter:app:create', [
            'name' => 'Acme iOS',
            '--platforms' => 'ios, android',
            '--min-version' => '1.2.0',
            '--latest-version' => '1.5.0',
            '--update-url' => 'https://example.test/update',
        ])
            ->expectsOutputToContain('App key: '.FlutterApp::KEY_PREFIX)
            ->assertSuccessful();

        $app = FlutterApp::query()->sole();
        $this->assertSame('Acme iOS', $app->name);
        $this->assertSame(['ios', 'android'], $app->platforms);
        $this->assertSame('1.2.0', $app->min_version);
        $this->assertMatchesRegularExpression('/^fba_[A-Za-z0-9]{40}$/', $app->key);

        $this->artisan('flutter:apps')->expectsOutputToContain('Acme iOS')->assertSuccessful();
    }

    public function test_app_update_and_delete(): void
    {
        $app = FlutterBridge::apps()->create('Acme');
        $connection = $this->connectDevice('phone', ['X-Flutter-App-Key' => $app->key]);

        $this->artisan('flutter:app:update', ['key' => $app->key, '--status' => 'suspended', '--min-version' => '2.0.0'])
            ->assertSuccessful();
        $this->assertTrue($app->fresh()->isSuspended());
        $this->assertSame('2.0.0', $app->fresh()->min_version);

        $this->artisan('flutter:app:update', ['key' => $app->key, '--status' => 'bogus'])->assertFailed();
        $this->artisan('flutter:app:update', ['key' => 'fba_missing', '--name' => 'x'])->assertFailed();

        $this->artisan('flutter:app:delete', ['key' => $app->key])
            ->expectsConfirmation('Delete app "Acme" and revoke all of its sessions?', 'no')
            ->assertFailed();

        $this->artisan('flutter:app:delete', ['key' => $app->key, '--force' => true])->assertSuccessful();
        $this->assertSame(0, FlutterApp::query()->count());
        $this->assertSame('revoked', FlutterConnection::query()->find($connection)->status);
    }

    public function test_connections_list_and_revoke(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('t')->plainTextToken;
        $a = $this->connectDevice('a', ['Authorization' => "Bearer {$token}"]);
        $b = $this->connectDevice('b', ['Authorization' => "Bearer {$token}"]);
        $this->connectDevice('c');

        $this->artisan('flutter:connections')
            ->expectsOutputToContain('Total: 3')
            ->assertSuccessful();

        $this->artisan('flutter:connections', ['--user' => (string) $user->id, '--status' => 'online'])
            ->expectsOutputToContain($a)
            ->assertSuccessful();

        $this->artisan('flutter:connections:revoke', ['id' => $a, '--reason' => 'lost'])->assertSuccessful();
        $this->assertSame('lost', FlutterConnection::query()->find($a)->revoked_reason);

        $this->artisan('flutter:connections:revoke', ['--user' => (string) $user->id])
            ->expectsOutputToContain('Revoked 1 connection(s)')
            ->assertSuccessful();
        $this->assertSame('revoked', FlutterConnection::query()->find($b)->status);

        $this->artisan('flutter:connections:revoke')->assertFailed();
        $this->artisan('flutter:connections:revoke', ['id' => 'missing'])->assertFailed();
    }

    public function test_connections_send(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('t')->plainTextToken;
        $a = $this->connectDevice('a', ['Authorization' => "Bearer {$token}"]);
        $b = $this->connectDevice('b');

        $this->artisan('flutter:connections:send', ['type' => 'message', 'id' => $a, '--payload' => '{"title":"Hi"}'])
            ->assertSuccessful();

        $command = FlutterConnectionCommand::query()->where('connection_id', $a)->sole();
        $this->assertSame('message', $command->type);
        $this->assertSame(['title' => 'Hi'], $command->payload);

        $this->artisan('flutter:connections:send', ['type' => 'logout', '--user' => (string) $user->id])->assertSuccessful();
        $this->artisan('flutter:connections:send', ['type' => 'refresh_config', '--all' => true])
            ->expectsOutputToContain('2 active connection(s)')
            ->assertSuccessful();

        $this->assertSame(3, FlutterConnectionCommand::query()->where('connection_id', $a)->count());
        $this->assertSame(1, FlutterConnectionCommand::query()->where('connection_id', $b)->count());

        $this->artisan('flutter:connections:send', ['type' => 'message', 'id' => $a, '--payload' => 'not json'])->assertFailed();
        $this->artisan('flutter:connections:send', ['type' => 'message'])->assertFailed();

        // Delivered through the heartbeat.
        $this->bridge('POST', "connections/{$b}/heartbeat")
            ->assertOk()
            ->assertJsonPath('data.commands.0.type', 'refresh_config');
    }

    public function test_connections_prune(): void
    {
        $this->createUser();
        $old = $this->connectDevice('old');
        $this->login(headers: ['X-Flutter-Connection-Id' => $old]);
        FlutterBridge::connections()->sendCommand($old, 'message');
        FlutterBridge::connections()->revoke($old);

        $this->travel(31)->days();
        $fresh = $this->connectDevice('fresh');

        $this->artisan('flutter:connections:prune')
            ->expectsOutputToContain('Pruned 1 connection(s)')
            ->assertSuccessful();

        $this->assertNull(FlutterConnection::query()->find($old));
        $this->assertNotNull(FlutterConnection::query()->find($fresh));
        $this->assertSame(0, FlutterConnectionCommand::query()->where('connection_id', $old)->count());
        $this->assertSame(0, FlutterRefreshToken::query()->count());

        $this->artisan('flutter:connections:prune', ['--days' => '0'])->assertSuccessful();
    }

    public function test_export_config_generates_dart_file(): void
    {
        $app = FlutterBridge::apps()->create('Acme');
        $dir = sys_get_temp_dir().'/flutter-bridge-export-'.uniqid();

        try {
            $this->artisan('flutter:export-config', [
                '--base-url' => 'https://api.acme.test/',
                '--app-key' => $app->key,
                '--output' => $dir,
            ])->assertSuccessful();

            $file = $dir.'/laravel_bridge_config.g.dart';
            $this->assertFileExists($file);
            $dart = (string) file_get_contents($file);

            $this->assertStringContainsString("import 'package:flutter_laravel_bridge/flutter_laravel_bridge.dart';", $dart);
            $this->assertStringContainsString('const laravelBridgeConfig = LaravelConnection(', $dart);
            $this->assertStringContainsString("id: 'default',", $dart);
            $this->assertStringContainsString("name: 'Bridge Test',", $dart);
            $this->assertStringContainsString("baseUrl: 'https://api.acme.test',", $dart);
            $this->assertStringContainsString("prefix: 'api/flutter',", $dart);
            $this->assertStringContainsString("appKey: '{$app->key}',", $dart);
            $this->assertStringContainsString('autoConfigureEcho: false,', $dart);

            $this->artisan('flutter:export-config', ['--app-key' => 'fba_missing', '--output' => $dir])->assertFailed();
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_export_config_escapes_dart_strings(): void
    {
        config()->set('app.name', "O'Brien \$App");
        $file = sys_get_temp_dir().'/flutter-bridge-export-'.uniqid().'.dart';

        try {
            $this->artisan('flutter:export-config', ['--output' => $file])->assertSuccessful();
            $dart = (string) file_get_contents($file);

            $this->assertStringContainsString("name: 'O\\'Brien \\\$App',", $dart);
            $this->assertStringNotContainsString('appKey:', $dart);
            $this->assertStringContainsString("baseUrl: 'https://api.example.test',", $dart);
        } finally {
            @unlink($file);
        }
    }

    public function test_status_shows_bridge_tables(): void
    {
        FlutterBridge::apps()->create('Acme');
        $this->connectDevice();

        $this->artisan('flutter:status')
            ->expectsOutputToContain('Client apps (1)')
            ->expectsOutputToContain('Connection sessions')
            ->assertSuccessful();
    }
}
