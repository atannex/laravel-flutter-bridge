<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Atannex\LaravelFlutterBridge\Tests\TestCase;

class DeviceTest extends TestCase
{
    public function test_anonymous_device_registration(): void
    {
        $this->bridge('POST', 'devices/register', [
            'device_id' => 'abc',
            'platform' => 'ios',
            'fcm_token' => 'token-1',
        ])->assertOk()->assertJsonPath('data.device.user_id', null);

        $this->assertSame('token-1', FlutterDevice::query()->sole()->fcm_token);
    }

    public function test_device_registration_with_optional_bearer_links_user(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->bridge('POST', 'devices/register', ['device_id' => 'abc', 'platform' => 'android'])->assertOk();

        $this->bridge('POST', 'devices/register', [
            'device_id' => 'abc',
            'platform' => 'android',
            'fcm_token' => 'token-2',
        ], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.device.user_id', $user->id);

        $device = FlutterDevice::query()->sole();
        $this->assertSame($user->id, $device->user_id);
        $this->assertSame(['token-2'], $user->getActiveFcmTokens());
    }

    public function test_anonymous_caller_cannot_take_over_or_delete_a_users_device(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('t')->plainTextToken;

        $this->bridge('POST', 'devices/register', [
            'device_id' => 'abc', 'platform' => 'android', 'fcm_token' => 'owner-token',
        ], ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->bridge('DELETE', 'devices/abc')
            ->assertOk()
            ->assertJsonPath('data.unregistered', false);

        $this->bridge('POST', 'devices/register', [
            'device_id' => 'abc', 'platform' => 'android', 'fcm_token' => 'attacker-token',
        ])->assertOk();

        $owned = FlutterDevice::query()->where('user_id', $user->id)->sole();
        $this->assertSame('owner-token', $owned->fcm_token);

        $this->bridge('DELETE', 'devices/abc', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.unregistered', true);

        $this->assertSame(0, FlutterDevice::query()->where('user_id', $user->id)->count());
    }
}
