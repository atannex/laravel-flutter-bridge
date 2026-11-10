<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Pluggable sign-in, sign-up, authenticator-app two-step check and user
 * presentation for the bridge authentication endpoints.
 *
 * Each step uses, in order: the `flutter-bridge.auth.*` option, Laravel
 * Fortify (when installed and configured), then the built-in behaviour.
 */
class FlutterAuthenticator
{
    public const TWO_FACTOR_REQUIRED = 'two_factor_required';

    public const TWO_FACTOR_INVALID = 'two_factor_invalid';

    protected const FORTIFY = 'Laravel\\Fortify\\Fortify';

    protected const FORTIFY_CREATES_USERS = 'Laravel\\Fortify\\Contracts\\CreatesNewUsers';

    protected const FORTIFY_TWO_FACTOR_PROVIDER = 'Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider';

    /**
     * Whether a custom (configured or Fortify) authenticator handles sign-in.
     */
    public function usesCustomAuthenticator(): bool
    {
        return $this->authenticator() !== null;
    }

    /**
     * Resolve the user for a sign-in request, or null when the credentials are wrong.
     *
     * @param class-string<Model> $userModel
     */
    public function authenticate(Request $request, string $userModel): ?Authenticatable
    {
        $authenticator = $this->authenticator();

        if ($authenticator !== null) {
            $user = $authenticator($request);

            return $user instanceof Authenticatable ? $user : null;
        }

        /** @var (Model&Authenticatable)|null $user */
        $user = $userModel::query()->where('email', (string) $request->input('email'))->first();

        if ($user === null || !Hash::check((string) $request->input('password'), $user->getAuthPassword())) {
            return null;
        }

        return $user;
    }

    /**
     * Whether a custom (configured or Fortify) action creates users.
     */
    public function usesCustomRegistration(): bool
    {
        return $this->registrar() !== null;
    }

    /**
     * Create a user with the configured or Fortify action.
     *
     * Validation exceptions thrown by the action propagate unchanged.
     *
     * @param array<string, mixed> $input
     */
    public function register(array $input, Request $request): Authenticatable
    {
        $registrar = $this->registrar();

        if ($registrar === null) {
            throw new LogicException('No custom registration action is configured.');
        }

        $user = $registrar($input, $request);

        if (!$user instanceof Authenticatable) {
            throw new LogicException('The flutter-bridge.auth.register_using action must return an Authenticatable user.');
        }

        return $user;
    }

    /**
     * Present a user for an auth response.
     */
    public function present(Authenticatable $user): mixed
    {
        $presenter = $this->resolveCallable(config('flutter-bridge.auth.user_presenter'), 'user_presenter');

        return $presenter !== null ? $presenter($user) : $user;
    }

    /**
     * Run the authenticator-app two-step check.
     *
     * Returns null when the user may receive tokens, otherwise
     * TWO_FACTOR_REQUIRED or TWO_FACTOR_INVALID.
     */
    public function checkTwoFactor(Authenticatable $user, Request $request): ?string
    {
        if (!$this->requiresTwoFactor($user)) {
            return null;
        }

        $code = $this->normalizeCode($request->input('code'));
        $recoveryCode = $this->normalizeCode($request->input('recovery_code'));

        if ($code === null && $recoveryCode === null) {
            return self::TWO_FACTOR_REQUIRED;
        }

        if (!$user instanceof Model) {
            return self::TWO_FACTOR_INVALID;
        }

        if ($code !== null && $this->validTotpCode($user, $code)) {
            return null;
        }

        if ($recoveryCode !== null && $this->consumeRecoveryCode($user, $recoveryCode)) {
            return null;
        }

        return self::TWO_FACTOR_INVALID;
    }

    /**
     * Whether the user must pass the authenticator-app check before tokens are issued.
     */
    public function requiresTwoFactor(Authenticatable $user): bool
    {
        $mode = config('flutter-bridge.auth.two_factor_challenge', 'auto');

        if ($mode === false || $mode === 'false' || $mode === 0 || $mode === '0' || !$user instanceof Model) {
            return false;
        }

        $fortifyInstalled = $this->fortifyInstalled();

        if (!$fortifyInstalled && ($mode === 'auto' || $mode === null)) {
            return false;
        }

        if (blank($user->getAttribute('two_factor_secret'))) {
            return false;
        }

        $fortify = self::FORTIFY;

        if ($fortifyInstalled
            && $fortify::confirmsTwoFactorAuthentication()
            && blank($user->getAttribute('two_factor_confirmed_at'))) {
            return false;
        }

        if (!$fortifyInstalled) {
            throw new LogicException('flutter-bridge.auth.two_factor_challenge is enabled but laravel/fortify is not installed.');
        }

        return true;
    }

    /**
     * Verify a TOTP code with Fortify's provider.
     */
    protected function validTotpCode(Model $user, string $code): bool
    {
        try {
            $secret = $this->encrypter()->decrypt($user->getAttribute('two_factor_secret'));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        $provider = app()->bound(self::FORTIFY_TWO_FACTOR_PROVIDER)
            ? app(self::FORTIFY_TWO_FACTOR_PROVIDER)
            : app('Laravel\\Fortify\\TwoFactorAuthenticationProvider');

        return (bool) $provider->verify($secret, $code);
    }

    /**
     * Match a recovery code and replace it so it cannot be used again.
     */
    protected function consumeRecoveryCode(Model $user, string $recoveryCode): bool
    {
        if (!method_exists($user, 'recoveryCodes') || !method_exists($user, 'replaceRecoveryCode')
            || blank($user->getAttribute('two_factor_recovery_codes'))) {
            return false;
        }

        try {
            $codes = (array) $user->recoveryCodes();
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        foreach ($codes as $stored) {
            if (is_string($stored) && hash_equals($stored, $recoveryCode)) {
                $user->replaceRecoveryCode($stored);

                return true;
            }
        }

        return false;
    }

    /**
     * The sign-in callable: config, then Fortify::authenticateUsing().
     */
    protected function authenticator(): ?callable
    {
        $configured = $this->resolveCallable(config('flutter-bridge.auth.authenticate_using'), 'authenticate_using');

        if ($configured !== null) {
            return $configured;
        }

        $fortify = self::FORTIFY;

        if ($this->fortifyInstalled() && is_callable($fortify::$authenticateUsingCallback ?? null)) {
            return $fortify::$authenticateUsingCallback;
        }

        return null;
    }

    /**
     * The sign-up callable: config, then Fortify's CreatesNewUsers action.
     */
    protected function registrar(): ?callable
    {
        $configured = config('flutter-bridge.auth.register_using');

        if ($configured !== null && $configured !== '') {
            $action = is_string($configured) && class_exists($configured) ? app($configured) : $configured;

            if (is_object($action) && !is_callable($action) && method_exists($action, 'create')) {
                return fn (array $input, Request $request) => $action->create($input);
            }

            return $this->resolveCallable($action, 'register_using');
        }

        if (interface_exists(self::FORTIFY_CREATES_USERS) && app()->bound(self::FORTIFY_CREATES_USERS)) {
            $action = app(self::FORTIFY_CREATES_USERS);

            return fn (array $input, Request $request) => $action->create($input);
        }

        return null;
    }

    /**
     * Resolve an invokable class-string or callable from config.
     */
    protected function resolveCallable(mixed $value, string $key): ?callable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && class_exists($value)) {
            $value = app($value);
        }

        if (!is_callable($value)) {
            throw new InvalidArgumentException("flutter-bridge.auth.{$key} must be null, an invokable class name or a callable.");
        }

        return $value;
    }

    protected function fortifyClass(): string
    {
        return self::FORTIFY;
    }

    protected function fortifyInstalled(): bool
    {
        return class_exists(self::FORTIFY);
    }

    /**
     * The encrypter Fortify uses for two-factor columns.
     *
     * @return \Illuminate\Contracts\Encryption\Encrypter
     */
    protected function encrypter()
    {
        // Fortify::currentEncrypter() only exists on recent Fortify releases.
        $fortify = $this->fortifyClass();

        if (method_exists($fortify, 'currentEncrypter')) {
            return $fortify::currentEncrypter();
        }

        return app('encrypter');
    }

    /**
     * Strip whitespace; empty becomes null.
     */
    protected function normalizeCode(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = (string) preg_replace('/\s+/', '', (string) $value);

        return $value === '' ? null : $value;
    }
}
