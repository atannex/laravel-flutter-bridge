<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests;

use Atannex\LaravelFlutterBridge\LaravelFlutterBridgeServiceProvider;
use Atannex\LaravelFlutterBridge\Notifications\Fortify\ResetPasswordCodeNotification;
use Atannex\LaravelFlutterBridge\Notifications\Fortify\VerifyEmailCodeNotification;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\CreateNewUser;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\FortifyUser;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\ResetUserPassword;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\UpdateUserPassword;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify\UpdateUserProfileInformation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use PragmaRX\Google2FA\Google2FA;

/**
 * A Fortify app as configured in practice (all features, confirm + confirmPassword).
 */
abstract class FortifyTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            FortifyServiceProvider::class,
            LaravelFlutterBridgeServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.users.model', FortifyUser::class);
        $app['config']->set('flutter-bridge.auth.user_model', FortifyUser::class);
        $app['config']->set('auth.password_timeout', 10800);

        $app['config']->set('fortify.guard', 'web');
        $app['config']->set('fortify.passwords', 'users');
        $app['config']->set('fortify.username', 'email');
        $app['config']->set('fortify.email', 'email');
        $app['config']->set('fortify.lowercase_usernames', true);
        $app['config']->set('fortify-options', []);
        $app['config']->set('fortify.features', [
            Features::registration(),
            Features::resetPasswords(),
            Features::emailVerification(),
            Features::updateProfileInformation(),
            Features::updatePasswords(),
            Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
            Features::passkeys(['confirmPassword' => true]),
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('home_calendar')->nullable();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/passkeys/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);

        Password::defaults(fn () => Password::min(12));

        Notification::fake();
    }

    protected function tearDown(): void
    {
        Fortify::$authenticateUsingCallback = null;
        Fortify::$confirmPasswordsUsingCallback = null;
        Password::$defaultCallback = null;

        parent::tearDown();
    }

    protected function makeUser(string $email = 'jane@example.test', bool $verified = true): FortifyUser
    {
        /** @var FortifyUser $user */
        $user = FortifyUser::query()->create([
            'name' => 'Jane',
            'email' => $email,
            'password' => 'secret-password',
        ]);

        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    /**
     * Sign in over the bridge; returns the access token.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function tokenFor(string $email = 'jane@example.test', array $extra = []): string
    {
        $response = $this->bridge('POST', 'auth/login', array_merge([
            'email' => $email,
            'password' => 'secret-password',
            'device_name' => 'Pixel 9',
        ], $extra));

        $response->assertOk();

        return (string) $response->json('data.authorization.access_token');
    }

    /**
     * Authenticated bridge request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function asToken(string $token, string $method, string $path, array $data = [], array $headers = []): TestResponse
    {
        return $this->bridge($method, $path, $data, array_merge($headers, ['Authorization' => 'Bearer '.$token]));
    }

    protected function confirmPassword(string $token): void
    {
        $this->asToken($token, 'POST', 'auth/user/confirm-password', ['password' => 'secret-password'])->assertOk();
    }

    /**
     * The last code emailed to a user with the given notification class.
     *
     * @param  class-string  $notification
     */
    protected function lastCode(object $notifiable, string $notification = VerifyEmailCodeNotification::class): string
    {
        $code = null;

        Notification::assertSentTo($notifiable, $notification, function ($sent) use (&$code) {
            $code = $sent->code;

            return true;
        });

        return (string) $code;
    }

    protected function lastResetCode(object $notifiable): string
    {
        return $this->lastCode($notifiable, ResetPasswordCodeNotification::class);
    }

    protected function otp(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    /**
     * Let a TOTP code be used again (Fortify caches used codes against replay).
     */
    protected function forgetUsedOtp(string $code): void
    {
        cache()->forget('fortify.2fa_codes.'.md5($code));
    }
}
