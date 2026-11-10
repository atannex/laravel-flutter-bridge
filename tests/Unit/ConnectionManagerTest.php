<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Unit;

use Atannex\LaravelFlutterBridge\Broadcasting\FlutterPresenceManager;
use Atannex\LaravelFlutterBridge\Facades\FlutterBridge;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Services\FlutterAppManager;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Services\FlutterTokenManager;
use Atannex\LaravelFlutterBridge\Tests\TestCase;

class ConnectionManagerTest extends TestCase
{
    public function test_facade_exposes_singleton_managers(): void
    {
        $this->assertSame(app(FlutterConnectionManager::class), FlutterBridge::connections());
        $this->assertSame(app(FlutterAppManager::class), FlutterBridge::apps());
        $this->assertSame(app(FlutterTokenManager::class), FlutterBridge::tokens());
        $this->assertSame('2.0.0', FlutterBridge::version());
    }

    public function test_send_command_to_user_and_stats(): void
    {
        $manager = FlutterBridge::connections();

        $a = $manager->connect(['device_id' => 'a', 'platform' => 'ios']);
        $b = $manager->connect(['device_id' => 'b', 'platform' => 'ios']);
        $a->forceFill(['user_id' => 7])->save();
        $b->forceFill(['user_id' => 7])->save();
        $manager->connect(['device_id' => 'c', 'platform' => 'web']);

        $this->assertCount(2, $manager->sendCommandToUser(7, 'logout'));
        $this->assertTrue($manager->isUserOnline(7));
        $this->assertCount(2, $manager->forUser(7));

        $manager->revoke($b);
        $this->assertSame(1, $manager->revokeAllForUser(7));

        $stats = $manager->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['active']);
        $this->assertSame(2, $stats['revoked']);
        $this->assertSame(0, $stats['users_online']);
    }

    public function test_presence_manager_delegates_to_connections(): void
    {
        $manager = FlutterBridge::connections();
        $presence = app(FlutterPresenceManager::class);

        $connection = $manager->connect(['device_id' => 'a', 'platform' => 'ios']);
        $connection->forceFill(['user_id' => 9])->save();

        $this->assertTrue($presence->isOnline(9));
        $this->assertSame('a', $presence->getPresence(9)['device_id']);

        $presence->markOffline(9);
        $this->assertFalse($presence->isOnline(9));
        $this->assertNull($presence->getPresence(9));
        $this->assertSame(FlutterConnection::STATUS_DISCONNECTED, $connection->fresh()->status);

        $presence->markOnline(9, 'a');
        $this->assertTrue($presence->isOnline(9));
    }

    public function test_error_code_lands_in_meta(): void
    {
        $data = FlutterResponse::error('Nope', 403, null, [], 'app_suspended')->getData(true);
        $this->assertSame('app_suspended', $data['meta']['error_code']);

        $data = FlutterResponse::error('Nope', 400)->getData(true);
        $this->assertArrayNotHasKey('error_code', $data['meta']);

        $this->assertSame('validation_failed', FlutterResponse::validation(['a' => ['b']])->getData(true)['meta']['error_code']);
    }

    public function test_upgrade_requirement(): void
    {
        $apps = FlutterBridge::apps();
        $app = $apps->create('Acme', ['min_version' => '1.10.0']);

        $this->assertNotNull($apps->upgradeRequirement($app, '1.9.0'));
        $this->assertNull($apps->upgradeRequirement($app, '1.10.0'));
        $this->assertNull($apps->upgradeRequirement($app, null));
        $this->assertNull($apps->upgradeRequirement(null, '0.0.1'));
    }
}
