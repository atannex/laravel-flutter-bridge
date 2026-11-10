<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers;

use Atannex\LaravelFlutterBridge\Events\FlutterTwoFactorCodeIssued;
use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\IssuesFlutterTokens;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class TwoFactorController extends Controller
{
    use IssuesFlutterTokens;

    /**
     * Seconds a one-time code stays valid.
     */
    protected const CODE_TTL = 600;

    /**
     * Verify a two-factor authentication code from Flutter client.
     */
    public function verify(Request $request): JsonResponse
    {
        $this->ensureFeature('two_factor');

        $validated = $request->validate([
            'two_factor_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $cacheKey = "flutter_2fa:{$validated['two_factor_token']}";
        $cachedData = Cache::get($cacheKey);

        if (! is_array($cachedData) || ! isset($cachedData['code'], $cachedData['user_id'])) {
            return FlutterResponse::error('Invalid or expired verification session', 400, null, [], 'invalid_two_factor_session');
        }

        $attemptsKey = "flutter_2fa_attempts:{$validated['two_factor_token']}";

        if (! hash_equals((string) $cachedData['code'], $validated['code'])) {
            Cache::add($attemptsKey, 0, now()->addSeconds(self::CODE_TTL));
            $attempts = (int) Cache::increment($attemptsKey);

            if ($attempts >= $this->maxAttempts()) {
                Cache::forget($cacheKey);
                Cache::forget($attemptsKey);

                return FlutterResponse::error(
                    'Too many invalid verification codes. Please request a new code.',
                    400,
                    null,
                    [],
                    'two_factor_locked'
                );
            }

            return FlutterResponse::validation(['code' => ['Invalid verification code']], 'Invalid verification code');
        }

        Cache::forget($cacheKey);
        Cache::forget($attemptsKey);

        $userModelClass = $this->userModel();
        /** @var Model|null $user */
        $user = $userModelClass::query()->find($cachedData['user_id']);

        if (! $user) {
            return FlutterResponse::notFound('User not found');
        }

        if (! $this->supportsTokens($user)) {
            return FlutterResponse::error('User model does not support API tokens', 500, null, [], 'server_error');
        }

        $deviceName = $validated['device_name'] ?? (string) $request->header('User-Agent', 'Flutter-Device');
        $issued = $this->issueTokens($request, $user, $deviceName, $validated['device_id'] ?? null);

        return FlutterResponse::success(
            $this->authPayload($request, $user, $issued['authorization'], $issued['connection']),
            'Two-factor authentication verified successfully'
        );
    }

    /**
     * Send or re-send an OTP code to user's email or phone.
     *
     * The code is handed to listeners of FlutterTwoFactorCodeIssued for delivery.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $this->ensureFeature('two_factor');

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $userModelClass = $this->userModel();
        /** @var Model|null $user */
        $user = $userModelClass::query()->where('email', $validated['email'])->first();

        if (! $user) {
            return FlutterResponse::notFound('User account not found');
        }

        $token = bin2hex(random_bytes(24));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put("flutter_2fa:{$token}", [
            'user_id' => $user->getKey(),
            'code' => $code,
        ], now()->addSeconds(self::CODE_TTL));

        event(new FlutterTwoFactorCodeIssued($user, $code, $token, self::CODE_TTL));

        return FlutterResponse::success([
            'two_factor_token' => $token,
            'expires_in' => self::CODE_TTL,
            'debug_code' => app()->isLocal() && config('app.debug') ? $code : null,
        ], 'Verification code dispatched');
    }

    /**
     * Wrong codes allowed before a challenge is destroyed.
     */
    protected function maxAttempts(): int
    {
        return max(1, (int) config('flutter-bridge.two_factor.max_attempts', 5));
    }
}
