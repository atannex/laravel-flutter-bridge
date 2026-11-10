<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers;

use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\IssuesFlutterTokens;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Services\FlutterTokenManager;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use IssuesFlutterTokens;

    /**
     * Authenticate a Flutter client and issue device tokens.
     */
    public function login(Request $request): JsonResponse
    {
        $this->ensureFeature('auth');

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'fcm_token' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', 'max:32'],
        ]);

        $userModelClass = $this->userModel();
        /** @var (Model&\Illuminate\Contracts\Auth\Authenticatable)|null $user */
        $user = $userModelClass::query()->where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        if (!$this->supportsTokens($user)) {
            return FlutterResponse::error('User model does not support API tokens', 500, null, [], 'server_error');
        }

        $deviceName = $validated['device_name'] ?? (string) $request->header('User-Agent', 'Flutter-Client');
        $issued = $this->issueTokens($request, $user, $deviceName, $validated['device_id'] ?? null);

        // Register device if device_id provided
        if (!empty($validated['device_id']) && config('flutter-bridge.devices.enabled', true)) {
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
    public function register(Request $request): JsonResponse
    {
        $this->ensureFeature('registration');

        $userModelClass = $this->userModel();
        $table = (new $userModelClass())->getTable();

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

        $deviceName = $validated['device_name'] ?? 'Flutter-Client';
        $issued = $this->supportsTokens($user)
            ? $this->issueTokens($request, $user, $deviceName, $validated['device_id'] ?? null)
            : ['authorization' => [], 'connection' => null];

        return FlutterResponse::success(
            $this->authPayload($request, $user, $issued['authorization'], $issued['connection']),
            'Registration successful',
            201
        );
    }

    /**
     * Get the currently authenticated user with device metadata.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return FlutterResponse::unauthorized();
        }

        return FlutterResponse::success([
            'user' => $user,
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
