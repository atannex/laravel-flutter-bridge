<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature\Fortify;

use Atannex\LaravelFlutterBridge\Fortify\FortifyUserState;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\FortifyUser;
use Atannex\LaravelFlutterBridge\Tests\FortifyTestCase;
use Laravel\Fortify\Features;

class DiscoveryAndLimitsTest extends FortifyTestCase
{
    public function test_handshake_and_config_publish_fortify_features(): void
    {
        $expected = [
            'installed' => true,
            'enabled' => true,
            'registration' => true,
            'reset_passwords' => true,
            'email_verification' => true,
            'update_profile_information' => true,
            'update_passwords' => true,
            'two_factor_authentication' => true,
            'passkeys' => true,
            'two_factor_confirm' => true,
            'two_factor_confirm_password' => true,
            'passkeys_confirm_password' => true,
            'username' => 'email',
            'lowercase_usernames' => true,
            'password_min' => 12,
            'password_timeout' => 10800,
            'reset_channel' => 'code',
            'codes' => ['length' => 6, 'ttl' => 600],
        ];

        $this->bridge('GET', 'handshake')
            ->assertOk()
            ->assertJsonPath('data.features.fortify', $expected)
            ->assertJsonPath('data.features.auth', true);

        config(['flutter-bridge.remote_config' => ['theme' => 'dark', 'features' => ['beta' => true]]]);

        $this->bridge('GET', 'config')
            ->assertOk()
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.features.beta', true)
            ->assertJsonPath('data.features.fortify', $expected);
    }

    public function test_discovery_reflects_disabled_features(): void
    {
        config([
            'fortify-options' => [],
            'fortify.features' => [Features::twoFactorAuthentication()],
            'flutter-bridge.fortify.codes.length' => 8,
        ]);

        $this->bridge('GET', 'handshake')
            ->assertJsonPath('data.features.fortify.registration', false)
            ->assertJsonPath('data.features.fortify.reset_passwords', false)
            ->assertJsonPath('data.features.fortify.passkeys', false)
            ->assertJsonPath('data.features.fortify.two_factor_authentication', true)
            ->assertJsonPath('data.features.fortify.two_factor_confirm', false)
            ->assertJsonPath('data.features.fortify.two_factor_confirm_password', false)
            ->assertJsonPath('data.features.fortify.codes.length', 8);
    }

    public function test_integration_can_be_switched_off(): void
    {
        config(['flutter-bridge.fortify.enabled' => false]);
        $this->makeUser();
        $token = $this->tokenFor();

        $this->bridge('GET', 'handshake')
            ->assertJsonPath('data.features.fortify.installed', true)
            ->assertJsonPath('data.features.fortify.enabled', false)
            ->assertJsonPath('data.features.fortify.reset_passwords', false);

        $this->bridge('POST', 'auth/forgot-password', ['email' => 'jane@example.test'])
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
        $this->asToken($token, 'POST', 'auth/user/two-factor-authentication')
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');

        // §4 fields are not added either.
        $me = (array) $this->asToken($token, 'GET', 'auth/me')->json('data.user');
        $this->assertArrayNotHasKey('two_factor_enabled', $me);
    }

    public function test_user_state_helper_and_custom_presenters(): void
    {
        $user = $this->makeUser();

        $this->assertSame([
            'email_verified' => true,
            'email_verified_at' => $user->email_verified_at->toIso8601String(),
            'two_factor_enabled' => false,
            'two_factor_pending_confirmation' => false,
            'passkeys_count' => 0,
        ], FortifyUserState::for($user));

        config(['flutter-bridge.auth.user_presenter' => fn (FortifyUser $u) => ['id' => $u->id, 'email_verified' => 'kept']]);
        $token = $this->tokenFor();

        // A custom presenter decides its own shape...
        $this->asToken($token, 'GET', 'auth/me')->assertJsonPath('data.user', ['id' => $user->id, 'email_verified' => 'kept']);

        // ...unless user_state is true: the state is added, the presenter's keys win.
        config(['flutter-bridge.fortify.user_state' => true]);
        $this->asToken($token, 'GET', 'auth/me')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email_verified', 'kept')
            ->assertJsonPath('data.user.two_factor_enabled', false);
    }

    public function test_forgot_password_is_rate_limited_per_address(): void
    {
        config(['flutter-bridge.rate_limits.password_reset' => ['per_minute' => 2, 'per_ip_per_minute' => 100]]);
        $this->makeUser();

        $this->bridge('POST', 'auth/forgot-password', ['email' => 'jane@example.test'])->assertOk();
        $this->bridge('POST', 'auth/forgot-password', ['email' => 'jane@example.test'])->assertOk();
        $this->bridge('POST', 'auth/forgot-password', ['email' => 'JANE@example.test'])
            ->assertStatus(429)
            ->assertJsonPath('meta.error_code', 'too_many_requests')
            ->assertHeader('Retry-After');

        $this->bridge('POST', 'auth/forgot-password', ['email' => 'other@example.test'])->assertOk();
    }

    public function test_code_submissions_are_rate_limited(): void
    {
        config([
            'flutter-bridge.rate_limits.codes' => ['per_minute' => 3],
            'flutter-bridge.fortify.codes.max_attempts' => 100,
        ]);

        foreach (range(1, 3) as $i) {
            $this->bridge('POST', 'auth/reset-password/verify-code', ['email' => 'jane@example.test', 'code' => '000000'])->assertStatus(422);
        }

        $this->bridge('POST', 'auth/reset-password/verify-code', ['email' => 'jane@example.test', 'code' => '000000'])
            ->assertStatus(429);
    }

    public function test_confirm_password_and_verification_are_rate_limited_per_user(): void
    {
        config([
            'flutter-bridge.rate_limits.confirm_password' => ['per_minute' => 2],
            'flutter-bridge.rate_limits.verification' => ['per_minute' => 1],
        ]);
        $this->makeUser(verified: false);
        $token = $this->tokenFor();

        $this->asToken($token, 'POST', 'auth/user/confirm-password', ['password' => 'x'])->assertStatus(422);
        $this->asToken($token, 'POST', 'auth/user/confirm-password', ['password' => 'x'])->assertStatus(422);
        $this->asToken($token, 'POST', 'auth/user/confirm-password', ['password' => 'secret-password'])->assertStatus(429);

        $this->asToken($token, 'POST', 'auth/email/verification-notification')->assertOk();
        $this->asToken($token, 'POST', 'auth/email/verification-notification')->assertStatus(429);
    }

    public function test_account_endpoints_are_rate_limited(): void
    {
        config(['flutter-bridge.rate_limits.account' => ['per_minute' => 1]]);
        $this->makeUser();
        $token = $this->tokenFor();

        $this->asToken($token, 'GET', 'auth/user/passkeys')->assertOk();
        $this->asToken($token, 'GET', 'auth/user/passkeys')->assertStatus(429);
    }
}
