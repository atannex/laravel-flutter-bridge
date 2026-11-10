<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Recent password confirmation, per access token (the bearer equivalent of
 * Fortify's session key `auth.password_confirmed_at`). Lasts
 * `auth.password_timeout` seconds.
 */
class PasswordConfirmation
{
    /**
     * Mark the request's token as confirmed now; returns the seconds it lasts.
     */
    public function confirm(Request $request, Authenticatable $user): int
    {
        $timeout = $this->timeout();
        $now = now()->getTimestamp();

        if (($key = $this->key($request, $user)) !== null) {
            Cache::put($key, $now, $timeout);
        } elseif ($request->hasSession()) {
            $request->session()->put('auth.password_confirmed_at', $now);
        }

        return $timeout;
    }

    /**
     * Seconds left on the current confirmation (0 = not confirmed).
     */
    public function remaining(Request $request, Authenticatable $user): int
    {
        $key = $this->key($request, $user);

        if ($key !== null) {
            $confirmedAt = Cache::get($key);
        } elseif ($request->hasSession()) {
            $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        } else {
            $confirmedAt = null;
        }

        if (! is_numeric($confirmedAt)) {
            return 0;
        }

        return max(0, ((int) $confirmedAt + $this->timeout()) - now()->getTimestamp());
    }

    /**
     * Whether the current token confirmed its password recently.
     */
    public function confirmed(Request $request, Authenticatable $user): bool
    {
        return $this->remaining($request, $user) > 0;
    }

    /**
     * Forget the confirmation of the current token.
     */
    public function forget(Request $request, Authenticatable $user): void
    {
        if (($key = $this->key($request, $user)) !== null) {
            Cache::forget($key);
        }
    }

    public function timeout(): int
    {
        return max(1, (int) config('auth.password_timeout', 10800));
    }

    /**
     * Cache key for the current personal access token, or null without one.
     */
    protected function key(Request $request, Authenticatable $user): ?string
    {
        if (! method_exists($user, 'currentAccessToken')) {
            return null;
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof Model || $token->getKey() === null) {
            return null;
        }

        return 'flutter-bridge:password-confirmed:'.$token->getKey();
    }
}
