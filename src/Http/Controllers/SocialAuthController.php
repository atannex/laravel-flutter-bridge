<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers;

use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\IssuesFlutterTokens;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SocialAuthController extends Controller
{
    use IssuesFlutterTokens;

    /**
     * Authenticate via third-party mobile OAuth provider (Google, Apple, GitHub).
     *
     * The provider token is verified by, in order: the configured
     * `flutter-bridge.auth.social_user_resolver`, Laravel Socialite (when
     * installed), or — only when `social_trust_client_profile` is explicitly
     * enabled — the profile sent by the client.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $this->ensureFeature('social_auth');

        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:google,apple,github'],
            'token' => ['required', 'string'],
            'email' => ['nullable', 'string', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'provider_id' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'recovery_code' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $profile = $this->resolveProfile($validated, $request);

        if ($profile === false) {
            return FlutterResponse::error(
                'Social authentication is not configured on this server',
                501,
                null,
                [],
                'social_auth_not_configured'
            );
        }

        if ($profile === null) {
            return FlutterResponse::error('The social provider token could not be verified', 401, null, [], 'invalid_social_token');
        }

        $userModelClass = $this->userModel();
        $email = $profile['email'] ?? null;
        $name = $profile['name'] ?? ($validated['name'] ?? ucfirst($validated['provider']).' User');

        /** @var Model|null $user */
        $user = $email ? $userModelClass::query()->where('email', $email)->first() : null;

        if (! $user) {
            if (! $email) {
                return FlutterResponse::validation(
                    ['email' => ['Email address is required for first-time social sign in']],
                    'Email address is required for first-time social sign in'
                );
            }

            /** @var Model $user */
            $user = $userModelClass::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
            ]);

            if (Schema::connection($user->getConnectionName())->hasColumn($user->getTable(), 'email_verified_at')) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        }

        // Accounts protected by an authenticator app still need their code.
        if ($user instanceof Authenticatable && ($challenge = $this->twoFactorChallenge($request, $user))) {
            return $challenge;
        }

        if (! $this->supportsTokens($user)) {
            return FlutterResponse::error('User model does not support API tokens', 500, null, [], 'server_error');
        }

        $deviceName = $validated['device_name'] ?? ($validated['provider'].'-Mobile-Login');
        $issued = $this->issueTokens($request, $user, $deviceName, $validated['device_id'] ?? null);

        if (! empty($validated['device_id']) && config('flutter-bridge.devices.enabled', true)) {
            app('flutter.bridge')->registerDevice($user->getKey(), $validated['device_id'], [
                'platform' => $request->header('X-Flutter-Platform', 'unknown'),
                'device_name' => $deviceName,
            ]);
        }

        return FlutterResponse::success(
            $this->authPayload($request, $user, $issued['authorization'], $issued['connection']),
            'Social authentication successful'
        );
    }

    /**
     * Resolve the verified provider profile.
     *
     * @param  array<string, mixed>  $validated
     * @return array{email?: string|null, name?: string|null, id?: string|null}|null|false
     *                                                                                     false = no verification strategy configured, null = token rejected
     */
    protected function resolveProfile(array $validated, Request $request): array|null|false
    {
        $resolver = config('flutter-bridge.auth.social_user_resolver');

        if ($resolver !== null && $resolver !== '') {
            $callable = is_string($resolver) ? app($resolver) : $resolver;

            try {
                $profile = $callable($validated['provider'], $validated['token'], $request);
            } catch (Throwable $e) {
                report($e);

                return null;
            }

            return is_array($profile) ? $profile : null;
        }

        $socialite = 'Laravel\\Socialite\\Facades\\Socialite';

        if (class_exists($socialite)) {
            try {
                $driver = $socialite::driver($validated['provider']);

                if (method_exists($driver, 'stateless')) {
                    $driver = $driver->stateless();
                }

                $socialUser = $driver->userFromToken($validated['token']);
            } catch (Throwable) {
                return null;
            }

            return [
                'email' => $socialUser->getEmail(),
                'name' => $socialUser->getName(),
                'id' => (string) $socialUser->getId(),
            ];
        }

        if (config('flutter-bridge.auth.social_trust_client_profile', false)) {
            return [
                'email' => $validated['email'] ?? null,
                'name' => $validated['name'] ?? null,
                'id' => $validated['provider_id'] ?? null,
            ];
        }

        return false;
    }
}
