<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Traits;

use Atannex\LaravelFlutterBridge\Support\BridgeRequest;

/**
 * For MustVerifyEmail users: on a bridge request (sign-up or an email change
 * from the app) the bridge already emails a verification code, so Laravel's
 * web verification link is not sent as well. Web requests keep the link.
 *
 * ```php
 * class User extends Authenticatable implements MustVerifyEmail
 * {
 *     use Notifiable, VerifiesEmailWithFlutterCodes;
 * }
 * ```
 */
trait VerifiesEmailWithFlutterCodes
{
    public function sendEmailVerificationNotification(): void
    {
        if ($this->flutterSendsVerificationCode()) {
            return;
        }

        parent::sendEmailVerificationNotification();
    }

    /**
     * Whether the current request is a bridge request whose verification the
     * bridge handles with an emailed code.
     */
    protected function flutterSendsVerificationCode(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        $request = request();

        if (! BridgeRequest::isBridgeRequest($request)) {
            return false;
        }

        $setting = $request->routeIs('flutter.auth.register')
            ? 'send_code_on_register'
            : 'send_code_on_email_change';

        return (bool) config('flutter-bridge.fortify.verification.'.$setting, true);
    }
}
