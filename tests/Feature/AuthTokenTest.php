<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Events\FlutterTwoFactorCodeIssued;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\SanctumOnlyUser;
use Atannex\LaravelFlutterBridge\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;

class AuthTokenTest extends TestCase
{
    public function test_login_binds_tokens_to_connection(): void
    {
        $user = $this->createUser();
        $id = $this->connectDevice('phone');

        $response = $this->bridge('POST', 'auth/login', [
            'email' => 'jane@example.test',
            'password' => 'secret-password',
            'device_name' => 'Pixel 9',
        ], ['X-Flutter-Connection-Id' => $id, 'X-Flutter-Device-Id' => 'phone']);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.authorization.token_type', 'Bearer')
            ->assertJsonPath('data.authorization.expires_in', 3600)
            ->assertJsonPath('data.connection.id', $id)
            ->assertJsonPath('data.connection.user_id', $user->id)
            ->assertJsonPath('data.connection.is_current', true)
            ->assertJsonStructure(['data' => ['authorization' => [
                'token_type', 'access_token', 'refresh_token', 'expires_in', 'expires_at', 'refresh_expires_at',
            ]]]);

        $refresh = (string) $response->json('data.authorization.refresh_token');
        $this->assertSame(80, strlen($refresh));

        $record = FlutterRefreshToken::query()->sole();
        $this->assertSame(hash('sha256', $refresh), $record->token);
        $this->assertSame($id, $record->connection_id);
        $this->assertSame('phone', $record->device_id);
        $this->assertSame($user->id, $record->user_id);
        $this->assertNotNull($record->access_token_id);
        $this->assertSame($user->id, FlutterConnection::query()->find($id)->user_id);
    }

    public function test_login_without_connection_header_has_no_connection_key(): void
    {
        $this->createUser();
        $data = $this->login();

        $this->assertArrayNotHasKey('connection', $data);
        $this->assertNull(FlutterRefreshToken::query()->sole()->connection_id);
    }

    public function test_invalid_credentials_return_validation_failed(): void
    {
        $this->createUser();

        $this->bridge('POST', 'auth/login', ['email' => 'jane@example.test', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_refresh_rotation(): void
    {
        $this->createUser();
        $id = $this->connectDevice('phone');
        $first = $this->login(headers: ['X-Flutter-Connection-Id' => $id])['authorization'];

        $response = $this->bridge('POST', 'auth/refresh', ['refresh_token' => $first['refresh_token']]);

        $response->assertOk()
            ->assertJsonPath('data.authorization.token_type', 'Bearer')
            ->assertJsonPath('data.connection.id', $id);

        $second = $response->json('data.authorization');
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNotSame($first['access_token'], $second['access_token']);

        // Old access token deleted, new one works.
        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->bridge('GET', 'auth/me', [], ['Authorization' => 'Bearer ' . $first['access_token']])->assertStatus(401);
        $this->bridge('GET', 'auth/me', [], ['Authorization' => 'Bearer ' . $second['access_token']])->assertOk();

        $old = FlutterRefreshToken::query()->where('token', hash('sha256', $first['refresh_token']))->sole();
        $new = FlutterRefreshToken::query()->where('token', hash('sha256', $second['refresh_token']))->sole();
        $this->assertSame(FlutterRefreshToken::REASON_ROTATED, $old->revoked_reason);
        $this->assertSame($new->id, $old->replaced_by_id);
        $this->assertSame($old->family, $new->family);
        $this->assertSame($id, $new->connection_id);
        $this->assertNull($new->revoked_at);
    }

    public function test_refresh_token_reuse_revokes_family(): void
    {
        $this->createUser();
        $first = $this->login(extra: ['device_id' => 'phone'])['authorization'];
        $second = $this->bridge('POST', 'auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertOk()
            ->json('data.authorization');

        $this->bridge('POST', 'auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertStatus(401)
            ->assertJsonPath('meta.error_code', 'refresh_token_reused');

        // The legitimately rotated token is revoked too.
        $this->bridge('POST', 'auth/refresh', ['refresh_token' => $second['refresh_token']])
            ->assertStatus(401)
            ->assertJsonPath('meta.error_code', 'invalid_refresh_token');

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame(0, FlutterRefreshToken::query()->whereNull('revoked_at')->count());
    }

    public function test_invalid_and_expired_refresh_tokens(): void
    {
        $this->bridge('POST', 'auth/refresh', ['refresh_token' => 'does-not-exist'])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.error_code', 'invalid_refresh_token');

        $this->createUser();
        $tokens = $this->login()['authorization'];
        $this->travel(43201)->minutes();

        $this->bridge('POST', 'auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertStatus(401)
            ->assertJsonPath('meta.error_code', 'invalid_refresh_token');

        $this->bridge('POST', 'auth/refresh', [])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed');
    }

    public function test_logout_revokes_refresh_token(): void
    {
        $this->createUser();
        $tokens = $this->login()['authorization'];

        $this->bridge('POST', 'auth/logout', [], ['Authorization' => 'Bearer ' . $tokens['access_token']])->assertOk();

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame(FlutterRefreshToken::REASON_LOGOUT, FlutterRefreshToken::query()->sole()->revoked_reason);

        $this->bridge('POST', 'auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertStatus(401)
            ->assertJsonPath('meta.error_code', 'invalid_refresh_token');
    }

    public function test_single_session_per_device_revokes_previous_tokens(): void
    {
        $this->createUser();
        $first = $this->login(headers: ['X-Flutter-Device-Id' => 'phone'])['authorization'];
        $this->login(headers: ['X-Flutter-Device-Id' => 'phone']);

        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->bridge('POST', 'auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertStatus(401)
            ->assertJsonPath('meta.error_code', 'invalid_refresh_token');
    }

    public function test_has_flutter_tokens_trait_persists_hashed_refresh_token(): void
    {
        $user = $this->createUser();
        $pair = $user->createFlutterToken('cli', ['*'], null, null, 'device-x');

        $record = FlutterRefreshToken::query()->sole();
        $this->assertSame(hash('sha256', $pair['refresh_token']), $record->token);
        $this->assertSame('device-x', $record->device_id);
        $this->assertSame(1, $user->flutterRefreshTokens()->count());
        $this->assertSame(1, $user->revokeAllFlutterTokens());
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_sanctum_only_user_model_is_supported(): void
    {
        config()->set('flutter-bridge.auth.user_model', SanctumOnlyUser::class);
        config()->set('auth.providers.users.model', SanctumOnlyUser::class);
        $this->createUser();

        $tokens = $this->login()['authorization'];
        $this->assertNotEmpty($tokens['refresh_token']);

        $rotated = $this->bridge('POST', 'auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertOk()
            ->json('data.authorization');

        $this->bridge('POST', 'auth/logout', [], ['Authorization' => 'Bearer ' . $rotated['access_token']])->assertOk();
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_register_issues_bound_tokens(): void
    {
        $id = $this->connectDevice('phone');

        $this->bridge('POST', 'auth/register', [
            'name' => 'New',
            'email' => 'new@example.test',
            'password' => 'password123',
        ], ['X-Flutter-Connection-Id' => $id])
            ->assertStatus(201)
            ->assertJsonPath('data.user.email', 'new@example.test')
            ->assertJsonPath('data.connection.id', $id);

        $this->assertSame($id, FlutterRefreshToken::query()->sole()->connection_id);
    }

    public function test_two_factor_flow_issues_tokens(): void
    {
        Event::fake([FlutterTwoFactorCodeIssued::class]);
        $user = $this->createUser();

        $token = $this->bridge('POST', 'auth/2fa/send-otp', ['email' => $user->email])
            ->assertOk()
            ->json('data.two_factor_token');

        $code = null;
        Event::assertDispatched(FlutterTwoFactorCodeIssued::class, function ($event) use (&$code) {
            $code = $event->code;

            return true;
        });

        $this->bridge('POST', 'auth/2fa/verify', ['two_factor_token' => $token, 'code' => '00000x'])
            ->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'validation_failed');

        $this->bridge('POST', 'auth/2fa/verify', ['two_factor_token' => $token, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['authorization' => ['refresh_token']]]);

        $this->assertSame(1, FlutterRefreshToken::query()->count());
    }

    public function test_social_auth_requires_verification_strategy(): void
    {
        $this->bridge('POST', 'auth/social', ['provider' => 'google', 'token' => 't', 'email' => 'x@example.test'])
            ->assertStatus(501)
            ->assertJsonPath('meta.error_code', 'social_auth_not_configured');
    }

    public function test_social_auth_with_resolver_issues_tokens(): void
    {
        config()->set('flutter-bridge.auth.social_user_resolver', function (string $provider, string $token) {
            return $token === 'valid' ? ['email' => 'social@example.test', 'name' => 'Social', 'id' => '42'] : null;
        });

        $this->bridge('POST', 'auth/social', ['provider' => 'google', 'token' => 'invalid'])
            ->assertStatus(401)
            ->assertJsonPath('meta.error_code', 'invalid_social_token');

        $this->bridge('POST', 'auth/social', ['provider' => 'google', 'token' => 'valid', 'email' => 'spoof@example.test'])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'social@example.test')
            ->assertJsonStructure(['data' => ['authorization' => ['access_token', 'refresh_token']]]);

        $this->assertSame(1, FlutterRefreshToken::query()->count());

        config()->set('flutter-bridge.features.social_auth', false);
        $this->bridge('POST', 'auth/social', ['provider' => 'google', 'token' => 'valid'])
            ->assertStatus(403)
            ->assertJsonPath('meta.error_code', 'feature_disabled');
    }
}
