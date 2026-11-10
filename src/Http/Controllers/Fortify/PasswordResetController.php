<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers\Fortify;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\InteractsWithFortify;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Services\FlutterTokenManager;
use Atannex\LaravelFlutterBridge\Services\VerificationCodeBroker;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use LogicException;
use Throwable;

/**
 * Forgot / reset password with an emailed code (or Fortify's email link token).
 */
class PasswordResetController extends Controller
{
    use InteractsWithFortify;

    protected const RESETS_USER_PASSWORDS = 'Laravel\\Fortify\\Contracts\\ResetsUserPasswords';

    protected const COMPLETE_PASSWORD_RESET = 'Laravel\\Fortify\\Actions\\CompletePasswordReset';

    /**
     * POST auth/forgot-password: always 200, whether or not the address has an account.
     */
    public function forgot(Request $request): JsonResponse
    {
        $this->fortify()->ensureEnabled('reset_passwords');

        $field = $this->fortify()->email();
        $this->fortify()->canonicalize($request, $field);
        $request->validate([$field => ['required', 'string', 'email', 'max:255']]);

        $email = (string) $request->input($field);
        $channel = $this->fortify()->resetChannel();

        // Same minimum duration with or without an account, so response times
        // do not reveal which addresses are registered (queue the mail if
        // delivery can take longer than the box).
        $expiresIn = (new Timebox)->call(function (Timebox $timebox) use ($channel, $field, $email): int {
            if ($channel === 'link') {
                // Fortify's own flow: the broker emails the web reset link (and throttles it).
                $this->broker()->sendResetLink([$field => $email]);

                return $this->linkLifetime();
            }

            $user = $this->findUser($email);

            if ($user !== null) {
                $issued = $this->codes()->issue(VerificationCodeBroker::PURPOSE_PASSWORD_RESET, $this->resetSubject($email));
                $this->notify($user, 'reset_password', $issued['code'], $issued['expires_in']);
            }

            return $this->codes()->ttl();
        }, (int) config('flutter-bridge.fortify.forgot_password_min_duration', 750_000));

        return FlutterResponse::success([
            'sent' => true,
            'channel' => $channel,
            'expires_in' => $expiresIn,
        ], __('If an account exists for this address, we have emailed instructions to reset the password.'));
    }

    /**
     * POST auth/reset-password/verify-code: trade the emailed code for a short-lived reset token.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $this->fortify()->ensureEnabled('reset_passwords');

        $field = $this->fortify()->email();
        $this->fortify()->canonicalize($request, $field);
        $request->validate([
            $field => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $email = (string) $request->input($field);
        $result = $this->codes()->verify(VerificationCodeBroker::PURPOSE_PASSWORD_RESET, $this->resetSubject($email), (string) $request->input('code'));

        if ($result !== VerificationCodeBroker::VALID) {
            throw FlutterBridgeException::code($result);
        }

        $user = $this->findUser($email);

        if ($user === null) {
            throw FlutterBridgeException::code(VerificationCodeBroker::INVALID);
        }

        $token = Str::random(64);
        $ttl = $this->resetTokenTtl();

        Cache::put($this->resetTokenKey($token), [
            'email' => Str::lower($email),
            'user_id' => $user->getKey(),
        ], $ttl);

        return FlutterResponse::success([
            'reset_token' => $token,
            'expires_in' => $ttl,
        ], __('Code verified. Choose a new password.'));
    }

    /**
     * POST auth/reset-password: with the bridge `reset_token` or Fortify's emailed `token`.
     *
     * The password is changed by Fortify's ResetsUserPasswords action through the
     * password broker, so its rules, the PasswordReset event and the token
     * bookkeeping are Fortify's; every bridge token and connection of the user
     * is then revoked.
     */
    public function reset(Request $request): JsonResponse
    {
        $this->fortify()->ensureEnabled('reset_passwords');

        $field = $this->fortify()->email();
        $this->fortify()->canonicalize($request, $field);
        $request->validate([
            $field => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'reset_token' => ['required_without:token', 'nullable', 'string', 'max:255'],
            'token' => ['required_without:reset_token', 'nullable', 'string', 'max:255'],
        ]);

        if (! app()->bound(self::RESETS_USER_PASSWORDS)) {
            throw new LogicException('Register Fortify::resetUserPasswordsUsing() to reset passwords over the bridge.');
        }

        $email = (string) $request->input($field);
        $resetToken = $request->filled('reset_token') ? (string) $request->input('reset_token') : null;
        $broker = $this->broker();

        if ($resetToken !== null) {
            $entry = Cache::get($this->resetTokenKey($resetToken));
            $user = $this->findUser($email);

            if (! is_array($entry)
                || ! hash_equals((string) ($entry['email'] ?? ''), Str::lower($email))
                || $user === null
                || (string) $user->getKey() !== (string) ($entry['user_id'] ?? '')) {
                throw FlutterBridgeException::resetTokenInvalid();
            }

            $brokerToken = $broker->createToken($user);
        } else {
            $brokerToken = (string) $request->input('token');
        }

        $resetUser = null;

        $status = $broker->reset([
            $field => $email,
            'password' => $request->input('password'),
            'password_confirmation' => $request->input('password_confirmation'),
            'token' => $brokerToken,
        ], function ($user) use ($request, &$resetUser) {
            app(self::RESETS_USER_PASSWORDS)->reset($user, $request->all());

            if (class_exists(self::COMPLETE_PASSWORD_RESET)) {
                app(self::COMPLETE_PASSWORD_RESET)($this->fortify()->guard(), $user);
            } else {
                $user->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }

            $resetUser = $user;
        });

        if ($status !== Password::PASSWORD_RESET || ! $resetUser instanceof Model) {
            throw FlutterBridgeException::resetTokenInvalid(__((string) $status));
        }

        if ($resetToken !== null) {
            Cache::forget($this->resetTokenKey($resetToken));
        }

        $this->codes()->forget(VerificationCodeBroker::PURPOSE_PASSWORD_RESET, $this->resetSubject($email));

        app(FlutterTokenManager::class)->revokeAllForUser($resetUser);

        if (config('flutter-bridge.connections.enabled', true)) {
            app(FlutterConnectionManager::class)->revokeAllForUser($resetUser->getKey(), null, 'password_reset');
        }

        return FlutterResponse::success(['reset' => true], __('Your password has been reset.'));
    }

    protected function broker(): PasswordBroker
    {
        $broker = Password::broker($this->fortify()->passwordBroker());

        if (! $broker instanceof PasswordBroker) {
            throw new LogicException('The bridge needs Laravel\'s password broker (Illuminate\\Auth\\Passwords\\PasswordBroker).');
        }

        return $broker;
    }

    /**
     * The user the broker resolves for an address, or null.
     */
    protected function findUser(string $email): (Model&CanResetPassword)|null
    {
        try {
            $user = $this->broker()->getUser([$this->fortify()->email() => $email]);
        } catch (Throwable) {
            return null;
        }

        return $user instanceof Model ? $user : null;
    }

    protected function resetSubject(string $email): string
    {
        return Str::lower(trim($email));
    }

    protected function resetTokenKey(string $token): string
    {
        return 'flutter-bridge:reset-token:'.hash('sha256', $token);
    }

    protected function resetTokenTtl(): int
    {
        return max(60, (int) config('flutter-bridge.fortify.reset_token_ttl', 900));
    }

    /**
     * Minutes the broker's emailed link lasts, in seconds.
     */
    protected function linkLifetime(): int
    {
        $broker = $this->fortify()->passwordBroker() ?? config('auth.defaults.passwords', 'users');

        return (int) config("auth.passwords.{$broker}.expire", 60) * 60;
    }
}
