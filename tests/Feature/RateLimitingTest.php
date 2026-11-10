<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Events\FlutterTwoFactorCodeIssued;
use Atannex\LaravelFlutterBridge\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class RateLimitingTest extends TestCase
{
    public function test_login_is_throttled_with_enveloped_429_and_retry_after(): void
    {
        config()->set('flutter-bridge.rate_limits.auth.per_minute', 2);
        $this->createUser();

        $attempt = fn () => $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'wrong']);

        $attempt()->assertStatus(422)->assertJsonPath('meta.error_code', 'validation_failed');
        $attempt()->assertStatus(422);

        $response = $attempt();
        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.error_code', 'too_many_requests')
            ->assertJsonStructure(['message', 'meta' => ['timestamp', 'api_version']])
            ->assertHeader('Retry-After')
            ->assertHeader('X-Laravel-Flutter-Bridge');

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));

        // The correct password is throttled too while the window lasts.
        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password'])
            ->assertStatus(429);
    }

    public function test_login_limit_is_isolated_per_email(): void
    {
        config()->set('flutter-bridge.rate_limits.auth.per_minute', 1);
        $this->createUser();
        $this->createUser('john@example.test');

        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'wrong'])->assertStatus(422);
        $this->bridge('POST', 'auth/login', ['email' => 'JANE@example.test', 'password' => 'wrong'])->assertStatus(429);

        $this->bridge('POST', 'auth/login', ['email' => 'john@example.test', 'password' => 'secret-password'])->assertOk();
    }

    public function test_auth_per_ip_limit_applies_across_emails(): void
    {
        config()->set('flutter-bridge.rate_limits.auth.per_ip_per_minute', 2);

        $this->bridge('POST', 'auth/login', ['email' => 'a@example.test', 'password' => 'x'])->assertStatus(422);
        $this->bridge('POST', 'auth/login', ['email' => 'b@example.test', 'password' => 'x'])->assertStatus(422);
        $this->bridge('POST', 'auth/login', ['email' => 'c@example.test', 'password' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('meta.error_code', 'too_many_requests');
    }

    public function test_two_factor_locks_after_max_wrong_codes(): void
    {
        config()->set('flutter-bridge.two_factor.max_attempts', 3);
        Event::fake([FlutterTwoFactorCodeIssued::class]);
        $this->createUser();

        $token = (string) $this->bridge('POST', 'auth/2fa/send-otp', ['email' => 'jane@example.test'])
            ->assertOk()
            ->json('data.two_factor_token');

        $code = '';
        Event::assertDispatched(FlutterTwoFactorCodeIssued::class, function (FlutterTwoFactorCodeIssued $event) use (&$code) {
            $code = $event->code;

            return true;
        });

        $wrong = $code === '000000' ? '111111' : '000000';

        $this->bridge('POST', 'auth/2fa/verify', ['two_factor_token' => $token, 'code' => $wrong])
            ->assertStatus(422)->assertJsonPath('meta.error_code', 'validation_failed');
        $this->bridge('POST', 'auth/2fa/verify', ['two_factor_token' => $token, 'code' => $wrong])
            ->assertStatus(422);
        $this->bridge('POST', 'auth/2fa/verify', ['two_factor_token' => $token, 'code' => $wrong])
            ->assertStatus(400)
            ->assertJsonPath('meta.error_code', 'two_factor_locked');

        // The challenge is gone: even the correct code no longer works.
        $this->bridge('POST', 'auth/2fa/verify', ['two_factor_token' => $token, 'code' => $code])
            ->assertStatus(400)
            ->assertJsonPath('meta.error_code', 'invalid_two_factor_session');
    }

    public function test_two_factor_verify_is_throttled(): void
    {
        config()->set('flutter-bridge.rate_limits.two_factor.per_minute', 1);

        $this->bridge('POST', 'auth/2fa/verify', ['two_factor_token' => 'nope', 'code' => '123456'])->assertStatus(400);
        $this->bridge('POST', 'auth/2fa/verify', ['two_factor_token' => 'nope', 'code' => '123456'])
            ->assertStatus(429)
            ->assertJsonPath('meta.error_code', 'too_many_requests');
    }

    public function test_refresh_is_throttled(): void
    {
        config()->set('flutter-bridge.rate_limits.refresh.per_minute', 2);

        $this->bridge('POST', 'auth/refresh', ['refresh_token' => 'bogus'])->assertStatus(401);
        $this->bridge('POST', 'auth/refresh', ['refresh_token' => 'bogus'])->assertStatus(401);
        $this->bridge('POST', 'auth/refresh', ['refresh_token' => 'bogus'])
            ->assertStatus(429)
            ->assertJsonPath('meta.error_code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }

    public function test_heartbeat_is_throttled_per_connection(): void
    {
        config()->set('flutter-bridge.rate_limits.connections.heartbeat_per_minute', 2);
        $first = $this->connectDevice('phone');
        $second = $this->connectDevice('tablet');

        $this->bridge('POST', "connections/{$first}/heartbeat")->assertOk();
        $this->bridge('POST', "connections/{$first}/heartbeat")->assertOk();
        $this->bridge('POST', "connections/{$first}/heartbeat")
            ->assertStatus(429)
            ->assertJsonPath('meta.error_code', 'too_many_requests')
            ->assertHeader('Retry-After');

        $this->bridge('POST', "connections/{$second}/heartbeat")->assertOk();
    }

    public function test_connect_is_throttled_per_device(): void
    {
        config()->set('flutter-bridge.rate_limits.connections.connect_per_minute', 1);

        $this->connectDevice('phone');
        $this->bridge('POST', 'connections/connect', ['device_id' => 'phone', 'platform' => 'android'])
            ->assertStatus(429);
        $this->connectDevice('tablet');
    }

    public function test_general_api_limiter(): void
    {
        config()->set('flutter-bridge.rate_limits.api.per_minute', 1);

        $this->bridge('GET', 'ping')->assertOk();
        $this->bridge('GET', 'handshake')
            ->assertStatus(429)
            ->assertJsonPath('meta.error_code', 'too_many_requests');
    }

    public function test_limiter_disabled_when_zero(): void
    {
        config()->set('flutter-bridge.rate_limits.auth.per_minute', 0);
        config()->set('flutter-bridge.rate_limits.auth.per_ip_per_minute', null);
        config()->set('flutter-bridge.rate_limits.refresh.per_minute', 0);

        for ($i = 0; $i < 8; $i++) {
            $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'wrong'])->assertStatus(422);
            $this->bridge('POST', 'auth/refresh', ['refresh_token' => 'bogus'])->assertStatus(401);
        }
    }
}
