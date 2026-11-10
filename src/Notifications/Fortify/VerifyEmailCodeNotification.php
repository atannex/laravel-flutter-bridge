<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Notifications\Fortify;

/**
 * The code for POST auth/email/verify.
 */
class VerifyEmailCodeNotification extends VerificationCodeNotification
{
    protected function subjectLine(): string
    {
        return 'Verify your :app email address';
    }

    protected function introLine(): string
    {
        return 'Enter this code in the :app app to verify your email address:';
    }

    protected function outroLine(): string
    {
        return 'If you did not create an account, no further action is required.';
    }
}
