<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Notifications\Fortify;

/**
 * The code for POST auth/reset-password/verify-code.
 */
class ResetPasswordCodeNotification extends VerificationCodeNotification
{
    protected function subjectLine(): string
    {
        return ':app password reset code';
    }

    protected function introLine(): string
    {
        return 'You are receiving this email because we received a password reset request for your :app account. Enter this code in the app:';
    }

    protected function outroLine(): string
    {
        return 'If you did not request a password reset, no further action is required.';
    }
}
