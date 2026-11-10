<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Fortify;

use Atannex\LaravelFlutterBridge\Support\Payload;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Account-security fields the app needs (docs/FORTIFY.md §4).
 *
 * Merged into the default user payload; a custom user_presenter can add them
 * with `array_merge(FortifyUserState::for($user), [...your fields])`.
 */
final class FortifyUserState
{
    /**
     * @return array{email_verified: bool, email_verified_at: string|null, two_factor_enabled: bool, two_factor_pending_confirmation: bool, passkeys_count?: int}
     */
    public static function for(Authenticatable $user): array
    {
        $bridge = app(FortifyBridge::class);

        $verifiedAt = $user instanceof Model ? $user->getAttribute('email_verified_at') : null;
        $emailVerified = method_exists($user, 'hasVerifiedEmail')
            ? (bool) $user->hasVerifiedEmail()
            : $verifiedAt !== null;

        $secret = $user instanceof Model ? $user->getAttribute('two_factor_secret') : null;
        $confirmedAt = $user instanceof Model ? $user->getAttribute('two_factor_confirmed_at') : null;
        $hasSecret = ! blank($secret);
        $confirms = $bridge->confirmsTwoFactor();

        $state = [
            'email_verified' => $emailVerified,
            'email_verified_at' => $verifiedAt instanceof DateTimeInterface
                ? Payload::date($verifiedAt)
                : (is_string($verifiedAt) ? $verifiedAt : null),
            'two_factor_enabled' => $hasSecret && (! $confirms || $confirmedAt !== null),
            'two_factor_pending_confirmation' => $hasSecret && $confirms && $confirmedAt === null,
        ];

        if ($bridge->enabled('passkeys') && $user instanceof Model && method_exists($user, 'passkeys')) {
            try {
                $state['passkeys_count'] = (int) $user->passkeys()->count();
            } catch (Throwable) {
                // The passkeys table is missing; leave the count out.
            }
        }

        return $state;
    }

    /**
     * Add the state to a presented user without overriding the presenter's own keys.
     *
     * flutter-bridge.fortify.user_state: "auto" (default) merges into the
     * default payload only, true also into a custom presenter's array, false never.
     */
    public static function merge(Authenticatable $user, mixed $presented, bool $customPresenter = false): mixed
    {
        $mode = config('flutter-bridge.fortify.user_state', 'auto');

        if (! app(FortifyBridge::class)->active()
            || $mode === false || $mode === 'false' || $mode === 0 || $mode === '0'
            || ($customPresenter && $mode !== true && $mode !== 'true' && $mode !== 1 && $mode !== '1')) {
            return $presented;
        }

        if ($presented instanceof Model) {
            $presented = $presented->toArray();
        }

        if (! is_array($presented)) {
            return $presented;
        }

        return array_merge(self::for($user), $presented);
    }
}
