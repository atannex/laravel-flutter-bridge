<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Tests\TestCase;

/**
 * Fortify not configured: the 2.1 behaviour, and the Fortify endpoints answer 404 feature_disabled.
 */
class WithoutFortifyTest extends TestCase
{
    public function test_fortify_endpoints_are_disabled(): void
    {
        $this->createUser();
        $token = (string) $this->login()['authorization']['access_token'];
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->bridge('GET', 'handshake')
            ->assertJsonPath('data.features.fortify.enabled', false)
            ->assertJsonPath('data.features.fortify.reset_passwords', false)
            ->assertJsonPath('data.features.fortify.username', 'email');

        foreach ([
            ['POST', 'auth/forgot-password', []],
            ['POST', 'auth/reset-password/verify-code', []],
            ['POST', 'auth/reset-password', []],
        ] as [$method, $path, $headers]) {
            $this->bridge($method, $path, [], $headers)->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
        }

        foreach ([
            ['POST', 'auth/email/verification-notification'],
            ['POST', 'auth/email/verify'],
            ['PUT', 'auth/user/profile-information'],
            ['PUT', 'auth/user/password'],
            ['POST', 'auth/user/confirm-password'],
            ['GET', 'auth/user/confirmed-password-status'],
            ['POST', 'auth/user/two-factor-authentication'],
            ['DELETE', 'auth/user/two-factor-authentication'],
            ['POST', 'auth/user/confirmed-two-factor-authentication'],
            ['GET', 'auth/user/two-factor-qr-code'],
            ['GET', 'auth/user/two-factor-secret-key'],
            ['GET', 'auth/user/two-factor-recovery-codes'],
            ['POST', 'auth/user/two-factor-recovery-codes'],
            ['GET', 'auth/user/passkeys'],
            ['PATCH', 'auth/user/passkeys/1'],
            ['DELETE', 'auth/user/passkeys/1'],
        ] as [$method, $path]) {
            $this->bridge($method, $path, [], $auth)
                ->assertStatus(404)
                ->assertJsonPath('success', false)
                ->assertJsonPath('meta.error_code', 'feature_disabled');
        }
    }

    public function test_register_and_me_keep_the_2_1_shape(): void
    {
        $response = $this->bridge('POST', 'auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'password123',
        ])->assertCreated()->assertJsonPath('data.email_verification_required', false);

        $this->assertArrayNotHasKey('two_factor_enabled', (array) $response->json('data.user'));
    }
}
