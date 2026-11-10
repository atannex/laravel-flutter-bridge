<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature\Fortify;

use Atannex\LaravelFlutterBridge\Notifications\Fortify\VerifyEmailCodeNotification;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\FortifyUser;
use Atannex\LaravelFlutterBridge\Tests\FortifyTestCase;
use Atannex\LaravelFlutterBridge\Traits\VerifiesEmailWithFlutterCodes;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

class EmailVerificationTest extends FortifyTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function registration(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'Ada@Example.test',
            'password' => 'long-enough-password',
            'password_confirmation' => 'long-enough-password',
            'agree' => true,
            'device_name' => 'Pixel 9',
        ], $overrides);
    }

    public function test_register_uses_creates_new_users_and_emails_a_verification_code(): void
    {
        Event::fake([Registered::class]);

        $response = $this->bridge('POST', 'auth/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('data.email_verification_required', true)
            ->assertJsonPath('data.user.email', 'ada@example.test')
            ->assertJsonPath('data.user.name', 'Ada Lovelace')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.user.two_factor_enabled', false)
            ->assertJsonStructure(['data' => ['authorization' => ['access_token', 'refresh_token']]]);

        Event::assertDispatched(Registered::class);
        $user = FortifyUser::query()->where('email', 'ada@example.test')->firstOrFail();
        $code = $this->lastCode($user);

        $token = (string) $response->json('data.authorization.access_token');

        Event::fake([Verified::class]);

        $this->asToken($token, 'POST', 'auth/email/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.email_verified', true);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->asToken($token, 'GET', 'auth/me')->assertOk()->assertJsonPath('data.user.email_verified', true);
    }

    public function test_registered_listeners_still_send_the_web_link(): void
    {
        // Registered in a Laravel app by its event service provider.
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

        $this->bridge('POST', 'auth/register', $this->registration())->assertCreated();

        $user = FortifyUser::query()->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertSentTo($user, VerifyEmailCodeNotification::class);
    }

    public function test_the_trait_skips_the_web_link_when_the_bridge_sends_a_code(): void
    {
        $saved = $this->makeUser(verified: false);
        $user = (new class extends FortifyUser
        {
            use VerifiesEmailWithFlutterCodes;
        })->newFromBuilder($saved->getAttributes());

        $this->app->instance('request', Request::create('/api/flutter/auth/user/profile-information', 'PUT'));
        $user->sendEmailVerificationNotification();
        Notification::assertNotSentTo($user, VerifyEmail::class);

        config(['flutter-bridge.fortify.verification.send_code_on_email_change' => false]);
        $user->sendEmailVerificationNotification();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);

        $this->app->instance('request', Request::create('/account', 'PUT'));
        $user->sendEmailVerificationNotification();
        Notification::assertSentToTimes($user, VerifyEmail::class, 2);
    }

    public function test_register_code_can_be_turned_off(): void
    {
        config(['flutter-bridge.fortify.verification.send_code_on_register' => false]);

        $this->bridge('POST', 'auth/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('data.email_verification_required', true);

        Notification::assertNotSentTo(FortifyUser::query()->firstOrFail(), VerifyEmailCodeNotification::class);
    }

    public function test_no_verification_required_without_the_feature(): void
    {
        config(['fortify.features' => [Features::registration()]]);

        $this->bridge('POST', 'auth/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('data.email_verification_required', false);

        Notification::assertNotSentTo(FortifyUser::query()->firstOrFail(), VerifyEmailCodeNotification::class);
    }

    public function test_register_validation_comes_from_the_action(): void
    {
        $this->bridge('POST', 'auth/register', $this->registration(['password' => 'short', 'password_confirmation' => 'short', 'agree' => false]))
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['password', 'agree']]);
    }

    public function test_register_follows_fortifys_registration_feature(): void
    {
        config(['fortify.features' => [Features::resetPasswords()]]);

        $this->bridge('POST', 'auth/register', $this->registration())
            ->assertStatus(404)
            ->assertJsonPath('meta.error_code', 'feature_disabled');

        $this->assertSame(0, FortifyUser::query()->count());
    }

    public function test_resend_replaces_the_previous_code(): void
    {
        $user = $this->makeUser(verified: false);
        $token = $this->tokenFor();

        $this->asToken($token, 'POST', 'auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data', ['sent' => true, 'expires_in' => 600]);
        $first = $this->lastCode($user);

        $this->asToken($token, 'POST', 'auth/email/verification-notification')->assertOk();
        $second = $this->lastCode($user);

        if ($first !== $second) {
            $this->asToken($token, 'POST', 'auth/email/verify', ['code' => $first])
                ->assertStatus(422)
                ->assertJsonPath('meta.error_code', 'code_invalid');
        }

        $this->asToken($token, 'POST', 'auth/email/verify', ['code' => $second])->assertOk();
    }

    public function test_wrong_expired_and_locked_codes(): void
    {
        config(['flutter-bridge.fortify.codes.max_attempts' => 2]);
        $user = $this->makeUser(verified: false);
        $token = $this->tokenFor();

        $this->asToken($token, 'POST', 'auth/email/verification-notification')->assertOk();
        $code = $this->lastCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->asToken($token, 'POST', 'auth/email/verify', ['code' => $wrong])
            ->assertStatus(422)->assertJsonPath('meta.error_code', 'code_invalid');
        $this->asToken($token, 'POST', 'auth/email/verify', ['code' => $wrong])
            ->assertStatus(423)->assertJsonPath('meta.error_code', 'code_locked');
        $this->asToken($token, 'POST', 'auth/email/verify', ['code' => $code])
            ->assertStatus(423)->assertJsonPath('meta.error_code', 'code_locked');

        $this->asToken($token, 'POST', 'auth/email/verification-notification')->assertOk();
        $code = $this->lastCode($user);
        $this->travel(11)->minutes();

        $this->asToken($token, 'POST', 'auth/email/verify', ['code' => $code])
            ->assertStatus(422)->assertJsonPath('meta.error_code', 'code_expired');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_code_for_another_user_does_not_work(): void
    {
        $jane = $this->makeUser(verified: false);
        $this->makeUser('john@example.test', verified: false);

        $this->asToken($this->tokenFor(), 'POST', 'auth/email/verification-notification')->assertOk();

        $this->asToken($this->tokenFor('john@example.test'), 'POST', 'auth/email/verify', ['code' => $this->lastCode($jane)])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'code_invalid');
    }

    public function test_already_verified_answers_409(): void
    {
        $this->makeUser();
        $token = $this->tokenFor();

        $this->asToken($token, 'POST', 'auth/email/verification-notification')
            ->assertStatus(409)
            ->assertJsonPath('meta.error_code', 'already_verified');

        $this->asToken($token, 'POST', 'auth/email/verify', ['code' => '123456'])
            ->assertStatus(409)
            ->assertJsonPath('meta.error_code', 'already_verified');

        Notification::assertNothingSent();
    }

    public function test_verification_needs_a_token_and_the_feature(): void
    {
        $this->bridge('POST', 'auth/email/verification-notification')->assertStatus(401);

        $this->makeUser(verified: false);
        $token = $this->tokenFor();
        config(['fortify.features' => [Features::registration()]]);

        $this->asToken($token, 'POST', 'auth/email/verification-notification')
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
        $this->asToken($token, 'POST', 'auth/email/verify', ['code' => '123456'])
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
    }
}
