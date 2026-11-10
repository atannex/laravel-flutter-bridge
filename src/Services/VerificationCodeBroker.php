<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Short numeric codes emailed to the user (password reset, email verification).
 *
 * Codes come from random_int(), are stored only as an HMAC in the cache,
 * expire after `fortify.codes.ttl` seconds, lock after
 * `fortify.codes.max_attempts` wrong guesses, are compared in constant time
 * and work once. Issuing a new code for the same purpose and subject
 * replaces the previous one (and its attempt counter).
 */
class VerificationCodeBroker
{
    public const VALID = 'valid';

    public const INVALID = 'code_invalid';

    public const EXPIRED = 'code_expired';

    public const LOCKED = 'code_locked';

    public const PURPOSE_PASSWORD_RESET = 'password-reset';

    public const PURPOSE_EMAIL_VERIFICATION = 'email-verification';

    /**
     * Seconds an expired or locked entry is kept so the client is told why it failed.
     */
    protected const GRACE = 3600;

    /**
     * Issue a new code; returns the plain code (to be emailed) and its lifetime.
     *
     * @return array{code: string, expires_in: int}
     */
    public function issue(string $purpose, string $subject): array
    {
        $length = $this->length();
        $ttl = $this->ttl();
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        $this->store()->put($this->key($purpose, $subject), [
            'hash' => $this->hash($purpose, $subject, $code),
            'expires_at' => now()->getTimestamp() + $ttl,
        ], $ttl + self::GRACE);

        $this->store()->forget($this->attemptsKey($purpose, $subject));

        return ['code' => $code, 'expires_in' => $ttl];
    }

    /**
     * Check a code: VALID (and consumed), INVALID, EXPIRED or LOCKED.
     */
    public function verify(string $purpose, string $subject, string $code): string
    {
        $key = $this->key($purpose, $subject);
        $entry = $this->store()->get($key);

        if (! is_array($entry) || ! isset($entry['hash'], $entry['expires_at'])) {
            return self::INVALID;
        }

        if (now()->getTimestamp() >= (int) $entry['expires_at']) {
            return self::EXPIRED;
        }

        $attemptsKey = $this->attemptsKey($purpose, $subject);

        if ((int) $this->store()->get($attemptsKey, 0) >= $this->maxAttempts()) {
            return self::LOCKED;
        }

        $code = (string) preg_replace('/\s+/', '', $code);

        if (hash_equals((string) $entry['hash'], $this->hash($purpose, $subject, $code))) {
            // Single use: whoever removes the entry first wins.
            if ($this->store()->pull($key) === null) {
                return self::INVALID;
            }

            $this->store()->forget($attemptsKey);

            return self::VALID;
        }

        $this->store()->add($attemptsKey, 0, $this->ttl() + self::GRACE);
        $attempts = (int) $this->store()->increment($attemptsKey);

        return $attempts >= $this->maxAttempts() ? self::LOCKED : self::INVALID;
    }

    /**
     * Forget any pending code.
     */
    public function forget(string $purpose, string $subject): void
    {
        $this->store()->forget($this->key($purpose, $subject));
        $this->store()->forget($this->attemptsKey($purpose, $subject));
    }

    /**
     * Digits per code (4 to 10, default 6).
     */
    public function length(): int
    {
        return min(10, max(4, (int) config('flutter-bridge.fortify.codes.length', 6)));
    }

    /**
     * Seconds a code stays valid (default 600).
     */
    public function ttl(): int
    {
        return max(60, (int) config('flutter-bridge.fortify.codes.ttl', 600));
    }

    /**
     * Wrong guesses before a code is locked (default 5).
     */
    public function maxAttempts(): int
    {
        return max(1, (int) config('flutter-bridge.fortify.codes.max_attempts', 5));
    }

    protected function hash(string $purpose, string $subject, string $code): string
    {
        return hash_hmac('sha256', $purpose.'|'.$subject.'|'.$code, $this->secret());
    }

    protected function key(string $purpose, string $subject): string
    {
        return 'flutter-bridge:code:'.$purpose.':'.hash('sha256', $subject);
    }

    protected function attemptsKey(string $purpose, string $subject): string
    {
        return $this->key($purpose, $subject).':attempts';
    }

    protected function secret(): string
    {
        $key = (string) config('app.key', '');

        return $key !== '' ? $key : 'flutter-bridge';
    }

    protected function store(): Repository
    {
        $store = config('flutter-bridge.fortify.codes.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
