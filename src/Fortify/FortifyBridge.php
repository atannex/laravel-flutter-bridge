<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Fortify;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Services\VerificationCodeBroker;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use ReflectionProperty;
use Throwable;

/**
 * Laravel Fortify integration for the bridge (docs/FORTIFY.md).
 *
 * Every Fortify class is referenced by name so the package keeps working
 * when laravel/fortify is not installed: the integration is then simply off.
 */
class FortifyBridge
{
    public const FORTIFY = 'Laravel\\Fortify\\Fortify';

    public const FEATURES = 'Laravel\\Fortify\\Features';

    public const PASSKEY_MODEL = 'Laravel\\Passkeys\\Passkey';

    /**
     * Bridge feature key => Fortify feature name.
     *
     * @var array<string, string>
     */
    public const FEATURE_MAP = [
        'registration' => 'registration',
        'reset_passwords' => 'reset-passwords',
        'email_verification' => 'email-verification',
        'update_profile_information' => 'update-profile-information',
        'update_passwords' => 'update-passwords',
        'two_factor_authentication' => 'two-factor-authentication',
        'passkeys' => 'passkeys',
    ];

    /**
     * Whether laravel/fortify is installed.
     */
    public function installed(): bool
    {
        return class_exists(self::FORTIFY);
    }

    /**
     * Whether the bridge's Fortify integration is on (flutter-bridge.fortify.enabled: auto|true|false).
     */
    public function active(): bool
    {
        $mode = config('flutter-bridge.fortify.enabled', 'auto');

        if ($mode === false || $mode === 'false' || $mode === 0 || $mode === '0') {
            return false;
        }

        // Installed and configured (config/fortify.php or Fortify's own defaults).
        return $this->installed() && is_array(config('fortify.features'));
    }

    /**
     * Whether a Fortify feature (bridge key, e.g. "reset_passwords") is enabled on the server.
     */
    public function enabled(string $feature): bool
    {
        if (! $this->active()) {
            return false;
        }

        if ($feature === 'passkeys' && ! $this->passkeysInstalled()) {
            return false;
        }

        $name = self::FEATURE_MAP[$feature] ?? $feature;
        $features = self::FEATURES;

        return (bool) $features::enabled($name);
    }

    /**
     * Abort with 404 feature_disabled unless the Fortify feature is enabled.
     *
     * @throws FlutterBridgeException
     */
    public function ensureEnabled(string $feature): void
    {
        if (! $this->enabled($feature)) {
            throw FlutterBridgeException::fortifyFeatureDisabled($feature);
        }
    }

    /**
     * Abort with 404 feature_disabled unless the integration is active (feature-less endpoints).
     *
     * @throws FlutterBridgeException
     */
    public function ensureActive(): void
    {
        if (! $this->active()) {
            throw FlutterBridgeException::fortifyFeatureDisabled('fortify');
        }
    }

    /**
     * Fortify's "confirm" option for two-factor authentication.
     */
    public function confirmsTwoFactor(): bool
    {
        return $this->active() && $this->optionEnabled('two_factor_authentication', 'confirm');
    }

    /**
     * Whether a sensitive action of a feature needs a recent password confirmation.
     *
     * Mirrors Fortify's route middleware: two-factor management uses the
     * "confirmPassword" option (off unless set); passkey management defaults to on.
     */
    public function requiresPasswordConfirmation(string $feature): bool
    {
        if (! $this->active()) {
            return false;
        }

        if ($feature === 'passkeys') {
            return (bool) config('fortify-options.passkeys.confirmPassword', true);
        }

        return $this->optionEnabled($feature, 'confirmPassword');
    }

    /**
     * Features::optionEnabled() for a bridge feature key.
     */
    public function optionEnabled(string $feature, string $option): bool
    {
        if (! $this->installed()) {
            return false;
        }

        $features = self::FEATURES;

        return (bool) $features::optionEnabled(self::FEATURE_MAP[$feature] ?? $feature, $option);
    }

    /**
     * The sign-in field (fortify.username), "email" without Fortify.
     */
    public function username(): string
    {
        if (! $this->active()) {
            return 'email';
        }

        $fortify = self::FORTIFY;

        return (string) $fortify::username();
    }

    /**
     * The email field used by password resets (fortify.email), "email" without Fortify.
     */
    public function email(): string
    {
        if (! $this->active()) {
            return 'email';
        }

        $fortify = self::FORTIFY;

        return (string) $fortify::email();
    }

    /**
     * Whether usernames are lower-cased before use (fortify.lowercase_usernames).
     */
    public function lowercasesUsernames(): bool
    {
        return $this->active() && (bool) config('fortify.lowercase_usernames', true);
    }

    /**
     * Lower-case the given request fields when Fortify does so (CanonicalizeUsername).
     */
    public function canonicalize(Request $request, string ...$fields): void
    {
        if (! $this->lowercasesUsernames()) {
            return;
        }

        foreach (array_unique($fields) as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $request->merge([$field => Str::lower($value)]);
            }
        }
    }

    /**
     * The password broker used for resets (fortify.passwords).
     */
    public function passwordBroker(): ?string
    {
        $broker = config('fortify.passwords');

        return is_string($broker) && $broker !== '' ? $broker : null;
    }

    /**
     * The encrypter Fortify uses for two-factor columns.
     */
    public function encrypter(): Encrypter
    {
        $fortify = self::FORTIFY;

        if ($this->installed()) {
            return $fortify::currentEncrypter();
        }

        return app('encrypter');
    }

    /**
     * Fortify's stateful guard (fortify.guard), used by Fortify's actions.
     */
    public function guard(): StatefulGuard
    {
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('fortify.guard', config('auth.defaults.guard', 'web')));

        return $guard;
    }

    /**
     * Let rules such as current_password:<fortify guard> see the bearer-token user.
     */
    public function actAs(Authenticatable $user): void
    {
        try {
            $this->guard()->setUser($user);
        } catch (Throwable) {
            // Not a guard that can hold a user; Fortify's actions still receive $user.
        }
    }

    /**
     * Whether laravel/passkeys is installed.
     */
    public function passkeysInstalled(): bool
    {
        return class_exists(self::PASSKEY_MODEL);
    }

    /**
     * Whether the user model exposes the passkeys() relation.
     */
    public function userHasPasskeys(mixed $user): bool
    {
        return $this->passkeysInstalled() && $user instanceof Model && method_exists($user, 'passkeys');
    }

    /**
     * The minimum password length from Password::defaults(), when it can be read.
     */
    public function passwordMin(): ?int
    {
        $configured = config('flutter-bridge.fortify.password_min');

        if (is_numeric($configured)) {
            return (int) $configured;
        }

        try {
            $rule = Password::default();
            $property = new ReflectionProperty($rule, 'min');
            $min = $property->getValue($rule);

            return is_numeric($min) ? (int) $min : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Seconds a password confirmation lasts (auth.password_timeout).
     */
    public function passwordTimeout(): int
    {
        return max(1, (int) config('auth.password_timeout', 10800));
    }

    /**
     * The features.fortify discovery block (handshake and GET config).
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        $block = ['installed' => $this->installed(), 'enabled' => $this->active()];

        foreach (array_keys(self::FEATURE_MAP) as $feature) {
            $block[$feature] = $this->enabled($feature);
        }

        $twoFactor = $block['two_factor_authentication'];
        $codes = app(VerificationCodeBroker::class);

        return array_merge($block, [
            'two_factor_confirm' => $twoFactor && $this->confirmsTwoFactor(),
            'two_factor_confirm_password' => $twoFactor && $this->requiresPasswordConfirmation('two_factor_authentication'),
            'passkeys_confirm_password' => $block['passkeys'] && $this->requiresPasswordConfirmation('passkeys'),
            'username' => $this->username(),
            'lowercase_usernames' => $this->lowercasesUsernames(),
            'password_min' => $this->active() ? $this->passwordMin() : null,
            'password_timeout' => $this->passwordTimeout(),
            'reset_channel' => $this->resetChannel(),
            'codes' => [
                'length' => $codes->length(),
                'ttl' => $codes->ttl(),
            ],
        ]);
    }

    /**
     * How forgot-password delivers: "code" (default) or "link" (Fortify's email link).
     */
    public function resetChannel(): string
    {
        return config('flutter-bridge.fortify.reset_channel', 'code') === 'link' ? 'link' : 'code';
    }
}
