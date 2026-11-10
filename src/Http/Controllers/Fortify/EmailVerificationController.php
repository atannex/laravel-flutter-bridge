<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers\Fortify;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\InteractsWithFortify;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Services\VerificationCodeBroker;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Email verification with an emailed code (Fortify's emailVerification feature).
 */
class EmailVerificationController extends Controller
{
    use InteractsWithFortify;

    /**
     * POST auth/email/verification-notification: email a new code.
     */
    public function send(Request $request): JsonResponse
    {
        $this->fortify()->ensureEnabled('email_verification');
        $user = $this->currentUser($request);

        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            throw FlutterBridgeException::alreadyVerified();
        }

        $expiresIn = $this->sendVerificationCode($user);

        return FlutterResponse::success([
            'sent' => true,
            'expires_in' => $expiresIn,
        ], __('A new verification code has been sent to your email address.'));
    }

    /**
     * POST auth/email/verify: check the code, mark the address verified, fire Verified.
     */
    public function verify(Request $request): JsonResponse
    {
        $this->fortify()->ensureEnabled('email_verification');
        $user = $this->currentUser($request);

        $request->validate(['code' => ['required', 'string', 'max:32']]);

        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            throw FlutterBridgeException::alreadyVerified();
        }

        $result = $this->codes()->verify(
            VerificationCodeBroker::PURPOSE_EMAIL_VERIFICATION,
            $this->verificationSubject($user),
            (string) $request->input('code')
        );

        if ($result !== VerificationCodeBroker::VALID) {
            throw FlutterBridgeException::code($result);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return FlutterResponse::success([
            'user' => $this->presentUser($user),
        ], __('Your email address has been verified.'));
    }
}
