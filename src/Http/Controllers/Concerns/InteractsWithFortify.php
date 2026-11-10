<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers\Concerns;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Fortify\FortifyBridge;
use Atannex\LaravelFlutterBridge\Services\FlutterAuthenticator;
use Atannex\LaravelFlutterBridge\Services\PasswordConfirmation;
use Atannex\LaravelFlutterBridge\Services\VerificationCodeBroker;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Shared helpers for the Fortify-backed bridge endpoints.
 */
trait InteractsWithFortify
{
    protected function fortify(): FortifyBridge
    {
        return app(FortifyBridge::class);
    }

    protected function codes(): VerificationCodeBroker
    {
        return app(VerificationCodeBroker::class);
    }

    /**
     * The authenticated Eloquent user.
     *
     * @throws AuthenticationException
     */
    protected function currentUser(Request $request): Model&Authenticatable
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * 423 password_confirmation_required unless the token confirmed its password
     * recently, when the feature asks for it (Fortify's confirmPassword option).
     *
     * @throws FlutterBridgeException
     */
    protected function ensurePasswordConfirmed(Request $request, Authenticatable $user, string $feature): void
    {
        if (! $this->fortify()->requiresPasswordConfirmation($feature)) {
            return;
        }

        if (! app(PasswordConfirmation::class)->confirmed($request, $user)) {
            throw FlutterBridgeException::passwordConfirmationRequired();
        }
    }

    /**
     * The user as the app presents it (auth.user_presenter + §4 state).
     */
    protected function presentUser(Authenticatable $user): mixed
    {
        return app(FlutterAuthenticator::class)->present($user);
    }

    /**
     * Whether the user still has to verify their email address.
     */
    protected function needsEmailVerification(Authenticatable $user): bool
    {
        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    /**
     * Code subject for email verification: the user and the address being verified.
     */
    protected function verificationSubject(Model&Authenticatable $user): string
    {
        return $user->getMorphClass().'|'.$user->getKey().'|'.strtolower((string) $this->emailOf($user));
    }

    /**
     * The address codes are sent to.
     */
    protected function emailOf(Authenticatable $user): ?string
    {
        if (method_exists($user, 'getEmailForVerification')) {
            return (string) $user->getEmailForVerification();
        }

        if ($user instanceof Model) {
            $email = $user->getAttribute($this->fortify()->email());

            return is_string($email) ? $email : null;
        }

        return null;
    }

    /**
     * Issue and email an email-verification code; returns its lifetime.
     */
    protected function sendVerificationCode(Model&Authenticatable $user): int
    {
        $issued = $this->codes()->issue(VerificationCodeBroker::PURPOSE_EMAIL_VERIFICATION, $this->verificationSubject($user));

        $this->notify($user, 'verify_email', $issued['code'], $issued['expires_in']);

        return $issued['expires_in'];
    }

    /**
     * Send one of the configurable code notifications (flutter-bridge.fortify.notifications.*).
     */
    protected function notify(Model $user, string $type, string $code, int $expiresIn): void
    {
        $class = config("flutter-bridge.fortify.notifications.{$type}");

        if (! is_string($class) || ! class_exists($class)) {
            throw new LogicException("flutter-bridge.fortify.notifications.{$type} must be a notification class.");
        }

        $notification = new $class($code, $expiresIn);

        if (! $notification instanceof Notification) {
            throw new LogicException("flutter-bridge.fortify.notifications.{$type} must extend Illuminate\\Notifications\\Notification.");
        }

        if (method_exists($user, 'notify')) {
            $user->notify($notification);

            return;
        }

        $email = $this->emailOf($user instanceof Authenticatable ? $user : throw new LogicException('Not an authenticatable user.'));

        if ($email !== null) {
            \Illuminate\Support\Facades\Notification::route('mail', $email)->notify($notification);
        }
    }
}
