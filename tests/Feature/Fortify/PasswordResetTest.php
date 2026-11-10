<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature\Fortify;

use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Atannex\LaravelFlutterBridge\Notifications\Fortify\ResetPasswordCodeNotification;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\CustomResetNotification;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\FortifyUser;
use Atannex\LaravelFlutterBridge\Tests\FortifyTestCase;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;

class PasswordResetTest extends FortifyTestCase
{
    /**
     * Request a reset code and verify it; returns the reset token.
     */
    protected function resetToken(string $email = 'jane@example.test'): string
    {
        $user = FortifyUser::query()->where('email', $email)->firstOrFail();

        $this->bridge('POST', 'auth/forgot-password', ['email' => $email])->assertOk();

        return (string) $this->bridge('POST', 'auth/reset-password/verify-code', [
            'email' => $email,
            'code' => $this->lastResetCode($user),
        ])->assertOk()->json('data.reset_token');
    }

    public function test_forgot_password_emails_a_code_and_answers_like_for_unknown_addresses(): void
    {
        $user = $this->makeUser();

        $known = $this->bridge('POST', 'auth/forgot-password', ['email' => 'JANE@example.test'])
            ->assertOk()
            ->assertJsonPath('data', ['sent' => true, 'channel' => 'code', 'expires_in' => 600]);

        $unknown = $this->bridge('POST', 'auth/forgot-password', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonPath('data', ['sent' => true, 'channel' => 'code', 'expires_in' => 600]);

        $this->assertSame($known->json('message'), $unknown->json('message'));

        Notification::assertSentToTimes($user, ResetPasswordCodeNotification::class, 1);
        Notification::assertCount(1);

        $code = $this->lastResetCode($user);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_code_mail_is_localizable_markdown_with_the_app_name(): void
    {
        $user = $this->makeUser();
        $this->bridge('POST', 'auth/forgot-password', ['email' => 'jane@example.test'])->assertOk();

        Notification::assertSentTo($user, ResetPasswordCodeNotification::class, function (ResetPasswordCodeNotification $notification) use ($user) {
            $mail = $notification->toMail($user);

            $this->assertSame('Bridge Test password reset code', $mail->subject);
            $this->assertContains('# '.$notification->code, $mail->introLines);
            $this->assertContains('This code expires in 10 minutes.', $mail->introLines);
            $this->assertSame(['mail'], $notification->via($user));

            return true;
        });
    }

    public function test_verify_code_returns_a_single_use_reset_token(): void
    {
        $this->makeUser();
        $token = $this->resetToken();

        $this->assertSame(64, strlen($token));

        // The code itself works once.
        $user = FortifyUser::query()->firstOrFail();
        $this->bridge('POST', 'auth/reset-password/verify-code', [
            'email' => 'jane@example.test',
            'code' => $this->lastResetCode($user),
        ])->assertStatus(422)->assertJsonPath('meta.error_code', 'code_invalid');
    }

    public function test_wrong_code_then_lockout_then_even_the_right_code_is_locked(): void
    {
        config(['flutter-bridge.fortify.codes.max_attempts' => 3]);
        $user = $this->makeUser();
        $this->bridge('POST', 'auth/forgot-password', ['email' => 'jane@example.test'])->assertOk();
        $code = $this->lastResetCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        $attempt = fn (string $c) => $this->bridge('POST', 'auth/reset-password/verify-code', ['email' => 'jane@example.test', 'code' => $c]);

        $attempt($wrong)->assertStatus(422)->assertJsonPath('meta.error_code', 'code_invalid')->assertJsonStructure(['errors' => ['code']]);
        $attempt($wrong)->assertStatus(422)->assertJsonPath('meta.error_code', 'code_invalid');
        $attempt($wrong)->assertStatus(423)->assertJsonPath('meta.error_code', 'code_locked');
        $attempt($code)->assertStatus(423)->assertJsonPath('meta.error_code', 'code_locked');

        // A new code starts over.
        $this->bridge('POST', 'auth/forgot-password', ['email' => 'jane@example.test'])->assertOk();
        $attempt($this->lastResetCode($user))->assertOk();
    }

    public function test_expired_code_is_reported(): void
    {
        $user = $this->makeUser();
        $this->bridge('POST', 'auth/forgot-password', ['email' => 'jane@example.test'])->assertOk();
        $code = $this->lastResetCode($user);

        $this->travel(601)->seconds();

        $this->bridge('POST', 'auth/reset-password/verify-code', ['email' => 'jane@example.test', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'code_expired');
    }

    public function test_unknown_address_gets_code_invalid(): void
    {
        $this->bridge('POST', 'auth/reset-password/verify-code', ['email' => 'nobody@example.test', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'code_invalid');
    }

    public function test_reset_with_reset_token_uses_fortify_and_revokes_bridge_sessions(): void
    {
        Event::fake([PasswordReset::class]);
        $user = $this->makeUser();
        $connectionId = $this->connectDevice();
        $this->login('jane@example.test', ['X-Flutter-Connection-Id' => $connectionId]);
        $this->tokenFor('jane@example.test', ['device_id' => 'tablet']);
        $this->assertSame(2, FlutterRefreshToken::query()->whereNull('revoked_at')->count());

        $resetToken = $this->resetToken();

        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'jane@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
            'reset_token' => $resetToken,
        ])->assertOk()->assertJsonPath('data', ['reset' => true]);

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        Event::assertDispatched(PasswordReset::class, fn (PasswordReset $e) => $e->user->is($user));

        $this->assertSame(0, FlutterRefreshToken::query()->whereNull('revoked_at')->count());
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(FlutterConnection::STATUS_REVOKED, FlutterConnection::query()->findOrFail($connectionId)->status);
        $this->assertDatabaseCount('password_reset_tokens', 0);

        // The reset token is single use.
        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'jane@example.test',
            'password' => 'another-new-password',
            'password_confirmation' => 'another-new-password',
            'reset_token' => $resetToken,
        ])->assertStatus(422)->assertJsonPath('meta.error_code', 'reset_token_invalid');
    }

    public function test_fortify_password_rules_apply_and_the_token_survives_a_rejected_password(): void
    {
        $this->makeUser();
        $resetToken = $this->resetToken();

        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'jane@example.test',
            'password' => 'short',
            'password_confirmation' => 'short',
            'reset_token' => $resetToken,
        ])->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['password']]);

        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'jane@example.test',
            'password' => 'long-enough-password',
            'password_confirmation' => 'long-enough-password',
            'reset_token' => $resetToken,
        ])->assertOk();
    }

    public function test_reset_token_is_bound_to_its_address(): void
    {
        $this->makeUser();
        $this->makeUser('john@example.test');
        $resetToken = $this->resetToken();

        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'john@example.test',
            'password' => 'long-enough-password',
            'password_confirmation' => 'long-enough-password',
            'reset_token' => $resetToken,
        ])->assertStatus(422)->assertJsonPath('meta.error_code', 'reset_token_invalid');

        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'jane@example.test',
            'password' => 'long-enough-password',
            'password_confirmation' => 'long-enough-password',
            'reset_token' => 'not-a-token',
        ])->assertStatus(422)->assertJsonPath('meta.error_code', 'reset_token_invalid');
    }

    public function test_reset_token_expires(): void
    {
        config(['flutter-bridge.fortify.reset_token_ttl' => 120]);
        $this->makeUser();
        $resetToken = $this->resetToken();

        $this->travel(121)->seconds();

        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'jane@example.test',
            'password' => 'long-enough-password',
            'password_confirmation' => 'long-enough-password',
            'reset_token' => $resetToken,
        ])->assertStatus(422)->assertJsonPath('meta.error_code', 'reset_token_invalid');
    }

    public function test_reset_with_the_broker_token_from_the_email_link(): void
    {
        Event::fake([PasswordReset::class]);
        $user = $this->makeUser();
        $token = Password::broker('users')->createToken($user);

        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'Jane@Example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
            'token' => $token,
        ])->assertOk()->assertJsonPath('data.reset', true);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        Event::assertDispatched(PasswordReset::class);

        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'jane@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
            'token' => $token,
        ])->assertStatus(422)->assertJsonPath('meta.error_code', 'reset_token_invalid');
    }

    public function test_reset_requires_a_token(): void
    {
        $this->makeUser();

        $this->bridge('POST', 'auth/reset-password', [
            'email' => 'jane@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['reset_token', 'token']]);
    }

    public function test_link_channel_sends_fortifys_reset_link(): void
    {
        config(['flutter-bridge.fortify.reset_channel' => 'link']);
        $user = $this->makeUser();

        $this->bridge('POST', 'auth/forgot-password', ['email' => 'jane@example.test'])
            ->assertOk()
            ->assertJsonPath('data', ['sent' => true, 'channel' => 'link', 'expires_in' => 3600]);

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertNotSentTo($user, ResetPasswordCodeNotification::class);
    }

    public function test_reset_endpoints_follow_fortifys_feature(): void
    {
        config(['fortify.features' => [Features::registration()]]);
        $this->makeUser();

        foreach ([
            ['auth/forgot-password', ['email' => 'jane@example.test']],
            ['auth/reset-password/verify-code', ['email' => 'jane@example.test', 'code' => '123456']],
            ['auth/reset-password', ['email' => 'jane@example.test', 'password' => 'x', 'token' => 'x']],
        ] as [$path, $body]) {
            $this->bridge('POST', $path, $body)
                ->assertStatus(404)
                ->assertJsonPath('success', false)
                ->assertJsonPath('meta.error_code', 'feature_disabled');
        }

        Notification::assertNothingSent();
    }

    public function test_reset_code_mail_class_is_configurable(): void
    {
        config(['flutter-bridge.fortify.notifications.reset_password' => CustomResetNotification::class]);
        $user = $this->makeUser();

        $this->bridge('POST', 'auth/forgot-password', ['email' => 'jane@example.test'])->assertOk();

        Notification::assertSentTo($user, CustomResetNotification::class);
    }
}
