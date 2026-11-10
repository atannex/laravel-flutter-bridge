<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature\Fortify;

use Atannex\LaravelFlutterBridge\Tests\FortifyTestCase;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Features;

class TwoFactorManagementTest extends FortifyTestCase
{
    public function test_full_two_factor_lifecycle(): void
    {
        Event::fake([
            TwoFactorAuthenticationEnabled::class,
            TwoFactorAuthenticationConfirmed::class,
            TwoFactorAuthenticationDisabled::class,
            TwoFactorAuthenticationChallenged::class,
            TwoFactorAuthenticationFailed::class,
            ValidTwoFactorAuthenticationCodeProvided::class,
            RecoveryCodesGenerated::class,
            RecoveryCodeReplaced::class,
        ]);

        $user = $this->makeUser();
        $token = $this->tokenFor();

        // Sensitive: needs a recent password confirmation.
        $this->asToken($token, 'POST', 'auth/user/two-factor-authentication')
            ->assertStatus(423)
            ->assertJsonPath('meta.error_code', 'password_confirmation_required');

        $this->confirmPassword($token);

        // Not enabled yet.
        $this->asToken($token, 'GET', 'auth/user/two-factor-qr-code')
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'two_factor_not_enabled');

        // enable
        $this->asToken($token, 'POST', 'auth/user/two-factor-authentication')
            ->assertOk()
            ->assertJsonPath('data', ['enabled' => true, 'confirmed' => false, 'requires_confirmation' => true]);
        Event::assertDispatched(TwoFactorAuthenticationEnabled::class);

        $this->asToken($token, 'GET', 'auth/me')
            ->assertJsonPath('data.user.two_factor_enabled', false)
            ->assertJsonPath('data.user.two_factor_pending_confirmation', true);

        // Unconfirmed: sign-in is not challenged yet.
        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password'])->assertOk();

        // qr + secret
        $qr = $this->asToken($token, 'GET', 'auth/user/two-factor-qr-code')->assertOk();
        $this->assertStringStartsWith('<svg', (string) $qr->json('data.svg'));
        $this->assertStringStartsWith('otpauth://totp/', (string) $qr->json('data.url'));

        $secret = (string) $this->asToken($token, 'GET', 'auth/user/two-factor-secret-key')->assertOk()->json('data.secret_key');
        $this->assertStringContainsString('secret='.$secret, (string) $qr->json('data.url'));

        // confirm
        $this->asToken($token, 'POST', 'auth/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_invalid')
            ->assertJsonStructure(['errors' => ['code']]);

        $code = $this->otp($secret);
        $this->asToken($token, 'POST', 'auth/user/confirmed-two-factor-authentication', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.confirmed', true)
            ->assertJsonPath('data.user.two_factor_enabled', true)
            ->assertJsonPath('data.user.two_factor_pending_confirmation', false);
        Event::assertDispatched(TwoFactorAuthenticationConfirmed::class);
        $this->forgetUsedOtp($code);

        // login challenge
        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_required')
            ->assertJsonPath('data.two_factor', true);
        Event::assertDispatched(TwoFactorAuthenticationChallenged::class);

        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_invalid');
        Event::assertDispatched(TwoFactorAuthenticationFailed::class);

        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password', 'code' => $this->otp($secret)])
            ->assertOk()
            ->assertJsonStructure(['data' => ['authorization' => ['access_token']]]);
        Event::assertDispatched(ValidTwoFactorAuthenticationCodeProvided::class);

        // recovery codes
        $codes = (array) $this->asToken($token, 'GET', 'auth/user/two-factor-recovery-codes')->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);

        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password', 'recovery_code' => $codes[0]])
            ->assertOk();
        Event::assertDispatched(RecoveryCodeReplaced::class);

        $after = (array) $this->asToken($token, 'GET', 'auth/user/two-factor-recovery-codes')->json('data.recovery_codes');
        $this->assertNotContains($codes[0], $after);
        $this->assertCount(8, $after);

        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password', 'recovery_code' => $codes[0]])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_invalid');

        // regenerate
        $fresh = (array) $this->asToken($token, 'POST', 'auth/user/two-factor-recovery-codes')->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $fresh);
        $this->assertEmpty(array_intersect($fresh, $after));
        Event::assertDispatched(RecoveryCodesGenerated::class);

        // disable
        $this->asToken($token, 'DELETE', 'auth/user/two-factor-authentication')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
        Event::assertDispatched(TwoFactorAuthenticationDisabled::class);

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password'])->assertOk();
    }

    public function test_every_management_endpoint_needs_a_confirmed_password(): void
    {
        $this->makeUser();
        $token = $this->tokenFor();

        foreach ([
            ['POST', 'auth/user/two-factor-authentication'],
            ['DELETE', 'auth/user/two-factor-authentication'],
            ['POST', 'auth/user/confirmed-two-factor-authentication'],
            ['GET', 'auth/user/two-factor-qr-code'],
            ['GET', 'auth/user/two-factor-secret-key'],
            ['GET', 'auth/user/two-factor-recovery-codes'],
            ['POST', 'auth/user/two-factor-recovery-codes'],
        ] as [$method, $path]) {
            $this->asToken($token, $method, $path, ['code' => '123456'])
                ->assertStatus(423)
                ->assertJsonPath('meta.error_code', 'password_confirmation_required');
        }
    }

    public function test_confirmation_expires_with_the_password_timeout(): void
    {
        config(['auth.password_timeout' => 300]);
        $this->makeUser();
        $token = $this->tokenFor();
        $this->confirmPassword($token);

        $this->asToken($token, 'POST', 'auth/user/two-factor-authentication')->assertOk();

        $this->travel(301)->seconds();

        $this->asToken($token, 'GET', 'auth/user/two-factor-qr-code')->assertStatus(423);
    }

    public function test_without_confirm_password_option_no_confirmation_is_needed(): void
    {
        config(['fortify.features' => [Features::twoFactorAuthentication(['confirm' => false])]]);
        $user = $this->makeUser();
        $token = $this->tokenFor();

        $this->asToken($token, 'POST', 'auth/user/two-factor-authentication')
            ->assertOk()
            ->assertJsonPath('data.requires_confirmation', false);

        // Without "confirm" the secret is active at once.
        $this->asToken($token, 'GET', 'auth/me')->assertJsonPath('data.user.two_factor_enabled', true);
        $this->assertNotNull($user->fresh()->two_factor_secret);

        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_required');
    }

    public function test_recovery_codes_and_secret_need_two_factor(): void
    {
        $this->makeUser();
        $token = $this->tokenFor();
        $this->confirmPassword($token);

        foreach ([
            ['GET', 'auth/user/two-factor-secret-key'],
            ['GET', 'auth/user/two-factor-recovery-codes'],
            ['POST', 'auth/user/two-factor-recovery-codes'],
        ] as [$method, $path]) {
            $this->asToken($token, $method, $path)
                ->assertStatus(404)
                ->assertJsonPath('meta.error_code', 'two_factor_not_enabled');
        }

        $this->asToken($token, 'POST', 'auth/user/confirmed-two-factor-authentication', ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_invalid');
    }

    public function test_two_factor_endpoints_follow_fortifys_feature(): void
    {
        config(['fortify.features' => [Features::registration()]]);
        $this->makeUser();
        $token = $this->tokenFor();

        $this->asToken($token, 'POST', 'auth/user/two-factor-authentication')
            ->assertStatus(404)
            ->assertJsonPath('meta.error_code', 'feature_disabled');
        $this->asToken($token, 'GET', 'auth/user/two-factor-qr-code')
            ->assertStatus(404)
            ->assertJsonPath('meta.error_code', 'feature_disabled');
    }

    public function test_enable_with_force_rotates_the_secret(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor();
        $this->confirmPassword($token);

        $this->asToken($token, 'POST', 'auth/user/two-factor-authentication')->assertOk();
        $first = $user->fresh()->two_factor_secret;

        $this->asToken($token, 'POST', 'auth/user/two-factor-authentication')->assertOk();
        $this->assertSame($first, $user->fresh()->two_factor_secret);

        $this->asToken($token, 'POST', 'auth/user/two-factor-authentication', ['force' => true])->assertOk();
        $this->assertNotSame($first, $user->fresh()->two_factor_secret);
    }
}
