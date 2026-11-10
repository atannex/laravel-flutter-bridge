<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Auth\AuthenticateUser;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Auth\CreateNewUser;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\Auth\PresentUser;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\TwoFactorUser;
use Atannex\LaravelFlutterBridge\Tests\TestCase;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Atannex\LaravelFlutterBridge\LaravelFlutterBridgeServiceProvider;
use PragmaRX\Google2FA\Google2FA;

class CustomAuthTest extends TestCase
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

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));

        $app['config']->set('auth.providers.users.model', TwoFactorUser::class);
        $app['config']->set('flutter-bridge.auth.user_model', TwoFactorUser::class);
        $app['config']->set('fortify.features', [Features::twoFactorAuthentication()]);
        $app['config']->set('fortify-options', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('role')->nullable();
            $table->string('home_calendar')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Fortify::$authenticateUsingCallback = null;

        parent::tearDown();
    }

    protected function makeUser(string $email = 'jane@example.test', array $attributes = []): TwoFactorUser
    {
        /** @var TwoFactorUser $user */
        $user = TwoFactorUser::query()->create(array_merge([
            'name' => 'Jane',
            'email' => $email,
            'password' => Hash::make('secret-password'),
        ], $attributes));

        return $user;
    }

    /**
     * Give the user an authenticator-app secret; returns the plain secret.
     *
     * @param list<string> $recoveryCodes
     */
    protected function enableTwoFactor(TwoFactorUser $user, bool $confirmed = true, array $recoveryCodes = ['aaaaaaaaaa-bbbbbbbbbb', 'cccccccccc-dddddddddd']): string
    {
        $secret = (new Google2FA())->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => $confirmed ? now() : null,
        ])->save();

        return $secret;
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function attemptLogin(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->bridge('POST', 'auth/login', array_merge([
            'email' => 'jane@example.test',
            'password' => 'secret-password',
            'device_name' => 'Pixel 9',
        ], $extra));
    }

    // ---------------------------------------------------------------- sign-in

    public function test_custom_authenticator_class_is_used(): void
    {
        config(['flutter-bridge.auth.authenticate_using' => AuthenticateUser::class]);
        $user = $this->makeUser();
        $this->makeUser('suspended@example.test', ['role' => 'suspended']);

        // The custom authenticator reads its own "login" field.
        $this->bridge('POST', 'auth/login', ['login' => 'jane@example.test', 'password' => 'secret-password'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['authorization' => ['access_token', 'refresh_token']]]);

        $this->bridge('POST', 'auth/login', ['email' => 'suspended@example.test', 'password' => 'secret-password'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_custom_authenticator_callable_is_used(): void
    {
        $user = $this->makeUser();
        $calls = 0;

        config(['flutter-bridge.auth.authenticate_using' => function (Request $request) use (&$calls, $user) {
            $calls++;

            return $request->input('password') === 'magic' ? $user : null;
        }]);

        $this->attemptLogin(['password' => 'magic'])->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->attemptLogin()->assertStatus(422)->assertJsonPath('meta.error_code', 'validation_failed');
        $this->assertSame(2, $calls);
    }

    public function test_fortify_authenticate_using_is_detected(): void
    {
        $user = $this->makeUser();

        Fortify::authenticateUsing(function (Request $request) use ($user) {
            return $request->input('password') === 'fortify-password' ? $user : null;
        });

        $this->attemptLogin()->assertStatus(422);
        $this->attemptLogin(['password' => 'fortify-password'])->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_config_authenticator_wins_over_fortify(): void
    {
        $user = $this->makeUser();
        Fortify::authenticateUsing(fn () => null);
        config(['flutter-bridge.auth.authenticate_using' => AuthenticateUser::class]);

        $this->attemptLogin()->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    // ------------------------------------------------------- two-step sign-in

    public function test_two_factor_required_when_code_missing(): void
    {
        $this->enableTwoFactor($this->makeUser());

        $this->attemptLogin()
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.error_code', 'two_factor_required')
            ->assertJsonPath('data.two_factor', true)
            ->assertJsonStructure(['errors' => ['code']]);

        $this->assertSame(0, FlutterRefreshToken::query()->count());
    }

    public function test_two_factor_required_also_with_fortify_authenticator(): void
    {
        config(['flutter-bridge.auth.authenticate_using' => AuthenticateUser::class]);
        $this->enableTwoFactor($this->makeUser());

        $this->attemptLogin()->assertStatus(422)->assertJsonPath('meta.error_code', 'two_factor_required');
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->enableTwoFactor($this->makeUser());

        $this->attemptLogin(['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_invalid')
            ->assertJsonPath('data.two_factor', true)
            ->assertJsonStructure(['errors' => ['code']]);

        $this->attemptLogin(['recovery_code' => 'not-a-code'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_invalid');

        $this->assertSame(0, FlutterRefreshToken::query()->count());
    }

    public function test_valid_totp_code_issues_tokens(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTwoFactor($user);
        $code = (new Google2FA())->getCurrentOtp($secret);

        // Whitespace is stripped.
        $this->attemptLogin(['code' => substr($code, 0, 3) . ' ' . substr($code, 3)])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['authorization' => ['access_token', 'refresh_token']]]);

        $this->assertSame(1, FlutterRefreshToken::query()->count());
    }

    public function test_recovery_code_works_once_and_is_replaced(): void
    {
        $user = $this->makeUser();
        $this->enableTwoFactor($user);

        $this->attemptLogin(['recovery_code' => 'aaaaaaaaaa-bbbbbbbbbb'])->assertOk();

        $codes = $user->fresh()->recoveryCodes();
        $this->assertCount(2, $codes);
        $this->assertNotContains('aaaaaaaaaa-bbbbbbbbbb', $codes);
        $this->assertContains('cccccccccc-dddddddddd', $codes);

        $this->attemptLogin(['recovery_code' => 'aaaaaaaaaa-bbbbbbbbbb'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_invalid');
    }

    public function test_challenge_can_be_turned_off(): void
    {
        config(['flutter-bridge.auth.two_factor_challenge' => false]);
        $this->enableTwoFactor($this->makeUser());

        $this->attemptLogin()->assertOk();
    }

    public function test_unconfirmed_secret_is_ignored_when_fortify_confirms(): void
    {
        config(['fortify.features' => [Features::twoFactorAuthentication(['confirm' => true])]]);
        $user = $this->makeUser();
        $this->enableTwoFactor($user, confirmed: false);

        $this->attemptLogin()->assertOk();

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->attemptLogin()->assertStatus(422)->assertJsonPath('meta.error_code', 'two_factor_required');
    }

    public function test_unconfirmed_secret_is_challenged_without_confirm_option(): void
    {
        $this->enableTwoFactor($this->makeUser(), confirmed: false);

        $this->attemptLogin()->assertStatus(422)->assertJsonPath('meta.error_code', 'two_factor_required');
    }

    public function test_social_sign_in_requires_two_factor_code(): void
    {
        config([
            'flutter-bridge.auth.social_user_resolver' => fn () => ['email' => 'jane@example.test', 'name' => 'Jane', 'id' => 'g-1'],
        ]);
        $secret = $this->enableTwoFactor($this->makeUser());

        $this->bridge('POST', 'auth/social', ['provider' => 'google', 'token' => 'provider-token'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'two_factor_required');

        $this->bridge('POST', 'auth/social', [
            'provider' => 'google',
            'token' => 'provider-token',
            'code' => (new Google2FA())->getCurrentOtp($secret),
        ])->assertOk()->assertJsonStructure(['data' => ['authorization' => ['access_token']]]);
    }

    // ----------------------------------------------------------------- sign-up

    public function test_custom_register_callable_receives_all_input(): void
    {
        Event::fake([Registered::class]);
        $received = null;

        config(['flutter-bridge.auth.register_using' => function (array $input, Request $request) use (&$received) {
            $received = $input;

            return TwoFactorUser::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'home_calendar' => $input['home_calendar'],
            ]);
        }]);

        $this->bridge('POST', 'auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'short',
            'home_calendar' => 'work',
            'device_name' => 'Pixel 9',
        ])->assertCreated()->assertJsonPath('data.user.home_calendar', 'work');

        $this->assertSame('work', $received['home_calendar'] ?? null);
        $this->assertSame('Pixel 9', $received['device_name'] ?? null);
        Event::assertDispatched(Registered::class, fn (Registered $e) => $e->user->email === 'ada@example.test');
    }

    public function test_custom_register_class_is_used(): void
    {
        config(['flutter-bridge.auth.register_using' => CreateNewUser::class]);

        $this->bridge('POST', 'auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'long-enough-password',
            'password_confirmation' => 'long-enough-password',
            'home_calendar' => 'family',
        ])->assertCreated()
            ->assertJsonPath('data.user.role', 'member')
            ->assertJsonPath('data.user.home_calendar', 'family');
    }

    public function test_fortify_creates_new_users_is_detected(): void
    {
        Event::fake([Registered::class]);
        Fortify::createUsersUsing(CreateNewUser::class);

        $this->bridge('POST', 'auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'long-enough-password',
            'password_confirmation' => 'long-enough-password',
            'home_calendar' => 'family',
        ])->assertCreated()
            ->assertJsonPath('data.user.role', 'member')
            ->assertJsonPath('data.user.home_calendar', 'family')
            ->assertJsonStructure(['data' => ['authorization' => ['access_token', 'refresh_token']]]);

        Event::assertDispatched(Registered::class);
        $this->assertTrue(app()->bound(CreatesNewUsers::class));
    }

    public function test_register_action_validation_errors_use_the_envelope(): void
    {
        Event::fake([Registered::class]);
        Fortify::createUsersUsing(CreateNewUser::class);

        // An 8-character password passes the built-in rules but not the app's 12-character policy.
        $this->bridge('POST', 'auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'eightchr',
            'password_confirmation' => 'eightchr',
        ])->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['password', 'home_calendar']]);

        $this->assertSame(0, TwoFactorUser::query()->count());
        Event::assertNotDispatched(Registered::class);
    }

    public function test_built_in_register_fires_registered(): void
    {
        Event::fake([Registered::class]);

        $this->bridge('POST', 'auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'password123',
        ])->assertCreated();

        Event::assertDispatched(Registered::class);
    }

    // --------------------------------------------------------------- presenter

    public function test_user_presenter_shapes_login_and_me(): void
    {
        config(['flutter-bridge.auth.user_presenter' => PresentUser::class]);
        $user = $this->makeUser();

        $login = $this->attemptLogin()
            ->assertOk()
            ->assertJsonPath('data.user', ['id' => $user->id, 'display_name' => 'JANE', 'presented' => true]);

        $token = (string) $login->json('data.authorization.access_token');

        $this->bridge('GET', 'auth/me', [], ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonPath('data.user.presented', true)
            ->assertJsonPath('data.user.display_name', 'JANE');
    }

    public function test_user_presenter_callable_shapes_register(): void
    {
        config(['flutter-bridge.auth.user_presenter' => fn ($user) => ['email' => $user->email]]);

        $this->bridge('POST', 'auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'password123',
        ])->assertCreated()->assertJsonPath('data.user', ['email' => 'ada@example.test']);
    }
}
