<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers;

use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\InteractsWithFortify;
use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\IssuesFlutterTokens;
use Atannex\LaravelFlutterBridge\Services\FlutterAuthenticator;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Services\FlutterTokenManager;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use InteractsWithFortify;
    use IssuesFlutterTokens;

    /**
     * Authenticate a Flutter client and issue device tokens.
     */
    public function login(Request $request, FlutterAuthenticator $authenticator): JsonResponse
    {
        $this->ensureFeature('auth');

        // A custom authenticator (config or Fortify) validates its own credential fields.
        $custom = $authenticator->usesCustomAuthenticator();
        $fortify = $this->fortify();
        $username = $fortify->username();

        // Fortify's CanonicalizeUsername (fortify.lowercase_usernames).
        $fortify->canonicalize($request, $username);

        $validated = $request->validate([
            $username => $custom
                ? ['nullable', 'string']
                : array_merge(['required', 'string'], $username === 'email' ? ['email'] : []),
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:255'],
            'recovery_code' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'fcm_token' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $authenticator->authenticate($request, $this->userModel());

        if ($user === null) {
            throw ValidationException::withMessages([
                $username => [trans('auth.failed')],
            ]);
        }

        if (! $user instanceof Model) {
            return FlutterResponse::error('User model does not support API tokens', 500, null, [], 'server_error');
        }

        if ($challenge = $this->twoFactorChallenge($request, $user)) {
            return $challenge;
        }

        if (! $this->supportsTokens($user)) {
            return FlutterResponse::error('User model does not support API tokens', 500, null, [], 'server_error');
        }

        $deviceName = $validated['device_name'] ?? (string) $request->header('User-Agent', 'Flutter-Client');
        $issued = $this->issueTokens($request, $user, $deviceName, $validated['device_id'] ?? null);

        // Register device if device_id provided
        if (! empty($validated['device_id']) && config('flutter-bridge.devices.enabled', true)) {
            app('flutter.bridge')->registerDevice($user->getKey(), $validated['device_id'], array_filter([
                'fcm_token' => $validated['fcm_token'] ?? null,
                'platform' => $validated['platform'] ?? $request->header('X-Flutter-Platform', 'unknown'),
                'device_name' => $deviceName,
            ], fn ($value) => $value !== null));
        }

        return FlutterResponse::success(
            $this->authPayload($request, $user, $issued['authorization'], $issued['connection']),
            'Authenticated successfully'
        );
    }

    /**
     * Register a new user from the Flutter client.
     */
    public function register(Request $request, FlutterAuthenticator $authenticator): JsonResponse
    {
        $this->ensureFeature('registration');

        // Mirror the server: no sign-up when Fortify's registration feature is off.
        if ($this->fortify()->active()) {
            $this->fortify()->ensureEnabled('registration');
            $this->fortify()->canonicalize($request, $this->fortify()->email());
        }

        if ($authenticator->usesCustomRegistration()) {
            // The action (config or Fortify CreatesNewUsers) validates its own fields.
            $validated = $request->validate([
                'device_name' => ['nullable', 'string', 'max:255'],
                'device_id' => ['nullable', 'string', 'max:255'],
            ]);

            $user = $authenticator->register($request->all(), $request);

            if (! $user instanceof Model) {
                return FlutterResponse::error('User model does not support API tokens', 500, null, [], 'server_error');
            }
        } else {
            $userModelClass = $this->userModel();
            $table = (new $userModelClass)->getTable();

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique($table, 'email')],
                'password' => ['required', 'string', 'min:8'],
                'device_name' => ['nullable', 'string', 'max:255'],
                'device_id' => ['nullable', 'string', 'max:255'],
            ]);

            /** @var Model $user */
            $user = $userModelClass::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
        }

        $verificationRequired = false;

        if ($user instanceof Authenticatable) {
            // Laravel's / Fortify's listeners run as for a web sign-up (e.g. the verification link).
            event(new Registered($user));

            // The app verifies with a code: email one right away (docs/FORTIFY.md).
            $verificationRequired = $this->fortify()->enabled('email_verification') && $this->needsEmailVerification($user);

            if ($verificationRequired && config('flutter-bridge.fortify.verification.send_code_on_register', true)) {
                $this->sendVerificationCode($user);
            }
        }

        $deviceName = $validated['device_name'] ?? 'Flutter-Client';
        $issued = $this->supportsTokens($user)
            ? $this->issueTokens($request, $user, $deviceName, $validated['device_id'] ?? null)
            : ['authorization' => [], 'connection' => null];

        $payload = $this->authPayload($request, $user, $issued['authorization'], $issued['connection']);
        $payload['email_verification_required'] = $verificationRequired;

        return FlutterResponse::success($payload, 'Registration successful', 201);
    }

    /**
     * Get the currently authenticated user with device metadata.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return FlutterResponse::unauthorized();
        }

        return FlutterResponse::success([
            'user' => app(FlutterAuthenticator::class)->present($user),
        ], 'User profile retrieved');
    }

    /**
     * Revoke the current access token and its refresh token(s), and log out.
     */
    public function logout(Request $request, FlutterTokenManager $tokens): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof Model) {
            $tokens->revokeCurrent($user);
        }

        return FlutterResponse::success(null, 'Successfully logged out');
    }

    /**
     * Rotate a refresh token into a new access / refresh pair (public route).
     */
    public function refresh(Request $request, FlutterTokenManager $tokens): JsonResponse
    {
        $this->ensureFeature('auth');

        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $tokens->rotate($validated['refresh_token'], BridgeRequest::connectionId($request));

        return FlutterResponse::success(
            $this->authPayload($request, null, $result['authorization'], $result['connection']),
            'Token refreshed successfully'
        );
    }
}
