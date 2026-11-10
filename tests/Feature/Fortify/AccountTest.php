<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature\Fortify;

use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Atannex\LaravelFlutterBridge\Notifications\Fortify\VerifyEmailCodeNotification;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\FortifyUser;
use Atannex\LaravelFlutterBridge\Tests\FortifyTestCase;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class AccountTest extends FortifyTestCase
{
    // ------------------------------------------------------------- profile

    public function test_profile_update_uses_the_fortify_action(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor();

        $this->asToken($token, 'PUT', 'auth/user/profile-information', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'home_calendar' => 'family',
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Jane Doe')
            ->assertJsonPath('data.user.home_calendar', 'family')
            ->assertJsonPath('data.email_verification_required', false);

        $this->assertSame('Jane Doe', $user->fresh()->name);
        Notification::assertNothingSent();
    }

    public function test_email_change_requires_verification_with_a_code(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor();

        $this->asToken($token, 'PUT', 'auth/user/profile-information', [
            'name' => 'Jane',
            'email' => 'Jane.New@Example.test',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'jane.new@example.test')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.email_verification_required', true);

        $user->refresh();
        Notification::assertSentTo($user, VerifyEmail::class);
        $code = $this->lastCode($user);

        $this->asToken($token, 'POST', 'auth/email/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.email_verified', true);
    }

    public function test_profile_validation_errors_use_the_envelope(): void
    {
        $this->makeUser();
        $this->makeUser('john@example.test');
        $token = $this->tokenFor();

        $this->asToken($token, 'PUT', 'auth/user/profile-information', ['name' => '', 'email' => 'john@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['name', 'email']]);

        Notification::assertNotSentTo(FortifyUser::query()->firstOrFail(), VerifyEmailCodeNotification::class);
    }

    // ------------------------------------------------------------ password

    public function test_password_update_checks_the_current_password(): void
    {
        $this->makeUser();
        $token = $this->tokenFor();

        $this->asToken($token, 'PUT', 'auth/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['current_password']]);

        $this->asToken($token, 'PUT', 'auth/user/password', [
            'current_password' => 'secret-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_password_update_with_revoke_of_other_sessions(): void
    {
        Event::fake([PasswordUpdatedViaController::class]);
        $user = $this->makeUser();
        $connectionId = $this->connectDevice('phone');
        $current = (string) $this->login('jane@example.test', ['X-Flutter-Connection-Id' => $connectionId], ['device_id' => 'phone'])['authorization']['access_token'];
        $otherConnection = $this->connectDevice('tablet');
        $other = (string) $this->login('jane@example.test', ['X-Flutter-Connection-Id' => $otherConnection], ['device_id' => 'tablet'])['authorization']['access_token'];
        $plain = $user->createToken('other-api-client')->plainTextToken;
        $resetToken = Password::broker('users')->createToken($user);

        $this->asToken($current, 'PUT', 'auth/user/password', [
            'current_password' => 'secret-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
            'revoke_other_sessions' => true,
        ], ['X-Flutter-Connection-Id' => $connectionId])->assertOk()
            ->assertJsonPath('data.updated', true)
            ->assertJsonPath('data.revoked_sessions', 2);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        Event::assertDispatched(PasswordUpdatedViaController::class);
        $this->assertFalse(Password::broker('users')->tokenExists($user, $resetToken));

        $this->asToken($current, 'GET', 'auth/me')->assertOk();
        $this->asToken($other, 'GET', 'auth/me')->assertStatus(401);
        $this->asToken($plain, 'GET', 'auth/me')->assertStatus(401);
        $this->assertSame(1, FlutterRefreshToken::query()->whereNull('revoked_at')->count());
        $this->assertSame(FlutterConnection::STATUS_REVOKED, FlutterConnection::query()->findOrFail($otherConnection)->status);
        $this->assertNotSame(FlutterConnection::STATUS_REVOKED, FlutterConnection::query()->findOrFail($connectionId)->status);
    }

    public function test_password_update_keeps_other_sessions_by_default(): void
    {
        $this->makeUser();
        $current = $this->tokenFor('jane@example.test', ['device_id' => 'phone']);
        $other = $this->tokenFor('jane@example.test', ['device_id' => 'tablet']);

        $this->asToken($current, 'PUT', 'auth/user/password', [
            'current_password' => 'secret-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk()->assertJsonPath('data.revoked_sessions', 0);

        $this->asToken($other, 'GET', 'auth/me')->assertOk();
    }

    public function test_profile_and_password_follow_fortifys_features(): void
    {
        $this->makeUser();
        $token = $this->tokenFor();
        config(['fortify.features' => [Features::registration()]]);

        $this->asToken($token, 'PUT', 'auth/user/profile-information', ['name' => 'X', 'email' => 'jane@example.test'])
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
        $this->asToken($token, 'PUT', 'auth/user/password', ['current_password' => 'secret-password'])
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
    }

    // ------------------------------------------------ password confirmation

    public function test_confirm_password_and_status(): void
    {
        config(['flutter-bridge.auth.access_token_ttl' => null]);
        $this->makeUser();
        $token = $this->tokenFor();

        $this->asToken($token, 'GET', 'auth/user/confirmed-password-status')
            ->assertOk()
            ->assertJsonPath('data', ['confirmed' => false, 'expires_in' => 0]);

        $this->asToken($token, 'POST', 'auth/user/confirm-password', ['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'password_invalid')
            ->assertJsonStructure(['errors' => ['password']]);

        $this->asToken($token, 'POST', 'auth/user/confirm-password', ['password' => 'secret-password'])
            ->assertOk()
            ->assertJsonPath('data', ['confirmed' => true, 'expires_in' => 10800]);

        $this->travel(3600)->seconds();

        $this->asToken($token, 'GET', 'auth/user/confirmed-password-status')
            ->assertOk()
            ->assertJsonPath('data', ['confirmed' => true, 'expires_in' => 7200]);

        $this->travel(7200)->seconds();

        $this->asToken($token, 'GET', 'auth/user/confirmed-password-status')
            ->assertOk()
            ->assertJsonPath('data.confirmed', false);
    }

    public function test_confirmation_belongs_to_one_token(): void
    {
        $this->makeUser();
        $phone = $this->tokenFor('jane@example.test', ['device_id' => 'phone']);
        $tablet = $this->tokenFor('jane@example.test', ['device_id' => 'tablet']);

        $this->confirmPassword($phone);

        $this->asToken($phone, 'GET', 'auth/user/confirmed-password-status')->assertJsonPath('data.confirmed', true);
        $this->asToken($tablet, 'GET', 'auth/user/confirmed-password-status')->assertJsonPath('data.confirmed', false);
    }

    public function test_confirm_password_honours_the_timeout_and_fortify_callback(): void
    {
        config(['auth.password_timeout' => 900]);
        $this->makeUser();
        $token = $this->tokenFor();

        Fortify::confirmPasswordsUsing(fn ($user, ?string $password) => $password === 'pin-1234');

        $this->asToken($token, 'POST', 'auth/user/confirm-password', ['password' => 'secret-password'])
            ->assertStatus(422)->assertJsonPath('meta.error_code', 'password_invalid');

        $this->asToken($token, 'POST', 'auth/user/confirm-password', ['password' => 'pin-1234'])
            ->assertOk()->assertJsonPath('data.expires_in', 900);
    }

    public function test_confirmation_endpoints_need_fortify(): void
    {
        $this->makeUser();
        $token = $this->tokenFor();
        config(['flutter-bridge.fortify.enabled' => false]);

        $this->asToken($token, 'POST', 'auth/user/confirm-password', ['password' => 'secret-password'])
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
        $this->asToken($token, 'GET', 'auth/user/confirmed-password-status')
            ->assertStatus(404)->assertJsonPath('meta.error_code', 'feature_disabled');
    }

    // ---------------------------------------------------------- sign-in

    public function test_sign_in_lowercases_the_username_like_fortify(): void
    {
        $this->makeUser();

        $this->bridge('POST', 'auth/login', ['email' => 'JANE@Example.TEST', 'password' => 'secret-password'])->assertOk();

        config(['fortify.lowercase_usernames' => false]);

        $this->bridge('POST', 'auth/login', ['email' => 'JANE@Example.TEST', 'password' => 'secret-password'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_sign_in_uses_fortifys_username_field(): void
    {
        config(['fortify.username' => 'name']);
        $this->makeUser();

        $this->bridge('POST', 'auth/login', ['name' => 'jane', 'password' => 'secret-password'])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
        $this->bridge('POST', 'auth/login', ['name' => 'Jane', 'password' => 'wrong'])->assertStatus(422);

        config(['fortify.lowercase_usernames' => false]);
        $this->bridge('POST', 'auth/login', ['name' => 'Jane', 'password' => 'secret-password'])->assertOk();
    }

    public function test_sign_in_uses_fortifys_authenticate_using(): void
    {
        $user = $this->makeUser();
        Fortify::authenticateUsing(fn ($request) => $request->input('password') === 'via-fortify' ? $user : null);

        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'secret-password'])->assertStatus(422);
        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'via-fortify'])->assertOk();
    }
}
