<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers\Fortify;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\InteractsWithFortify;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * Authenticator-app management through Fortify's actions and events.
 *
 * Like Fortify's routes, every endpoint here needs a recent password
 * confirmation when the twoFactorAuthentication "confirmPassword" option is on.
 */
class TwoFactorAuthenticationController extends Controller
{
    use InteractsWithFortify;

    protected const ENABLE = 'Laravel\\Fortify\\Actions\\EnableTwoFactorAuthentication';

    protected const CONFIRM = 'Laravel\\Fortify\\Actions\\ConfirmTwoFactorAuthentication';

    protected const DISABLE = 'Laravel\\Fortify\\Actions\\DisableTwoFactorAuthentication';

    protected const GENERATE_RECOVERY_CODES = 'Laravel\\Fortify\\Actions\\GenerateNewRecoveryCodes';

    protected const PROVIDER = 'Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider';

    /**
     * POST auth/user/two-factor-authentication (`force` regenerates an existing secret).
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $this->authorizeUser($request);

        app(self::ENABLE)($user, $request->boolean('force'));

        return FlutterResponse::success($this->status($user->refresh()), __('Two-factor authentication has been enabled.'));
    }

    /**
     * GET auth/user/two-factor-qr-code.
     */
    public function qrCode(Request $request): JsonResponse
    {
        $user = $this->authorizeUser($request);
        $this->ensureSecret($user);

        if (method_exists($user, 'twoFactorQrCodeUrl') && method_exists($user, 'twoFactorQrCodeSvg')) {
            $url = (string) $user->twoFactorQrCodeUrl();
            $svg = (string) $user->twoFactorQrCodeSvg();
        } else {
            $url = (string) app(self::PROVIDER)->qrCodeUrl(
                (string) config('app.name'),
                (string) $user->getAttribute($this->fortify()->username()),
                $this->secret($user)
            );
            $svg = null;
        }

        return FlutterResponse::success(['svg' => $svg, 'url' => $url], __('Two-factor QR code.'));
    }

    /**
     * GET auth/user/two-factor-secret-key.
     */
    public function secretKey(Request $request): JsonResponse
    {
        $user = $this->authorizeUser($request);
        $this->ensureSecret($user);

        return FlutterResponse::success(['secret_key' => $this->secret($user)], __('Two-factor secret key.'));
    }

    /**
     * POST auth/user/confirmed-two-factor-authentication.
     */
    public function confirm(Request $request): JsonResponse
    {
        $user = $this->authorizeUser($request);
        $request->validate(['code' => ['required', 'string', 'max:255']]);

        try {
            app(self::CONFIRM)($user, (string) preg_replace('/\s+/', '', (string) $request->input('code')));
        } catch (ValidationException $e) {
            throw FlutterBridgeException::twoFactorInvalid(
                (string) ($e->errors()['code'][0] ?? __('The provided two factor authentication code was invalid.'))
            );
        }

        $user->refresh();

        return FlutterResponse::success(array_merge(
            ['confirmed' => true],
            $this->status($user),
            ['user' => $this->presentUser($user)]
        ), __('Two-factor authentication has been confirmed.'));
    }

    /**
     * GET auth/user/two-factor-recovery-codes.
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->authorizeUser($request);
        $this->ensureSecret($user);

        if (blank($user->getAttribute('two_factor_recovery_codes'))) {
            throw FlutterBridgeException::twoFactorNotEnabled();
        }

        return FlutterResponse::success(['recovery_codes' => $this->codesOf($user)], __('Recovery codes.'));
    }

    /**
     * POST auth/user/two-factor-recovery-codes: a new set (RecoveryCodesGenerated).
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $this->authorizeUser($request);
        $this->ensureSecret($user);

        app(self::GENERATE_RECOVERY_CODES)($user);

        return FlutterResponse::success(['recovery_codes' => $this->codesOf($user->refresh())], __('New recovery codes have been generated.'));
    }

    /**
     * DELETE auth/user/two-factor-authentication.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $this->authorizeUser($request);

        app(self::DISABLE)($user);

        return FlutterResponse::success($this->status($user->refresh()), __('Two-factor authentication has been disabled.'));
    }

    /**
     * Feature on, signed in, password recently confirmed (when required).
     */
    protected function authorizeUser(Request $request): Model&Authenticatable
    {
        $this->fortify()->ensureEnabled('two_factor_authentication');
        $user = $this->currentUser($request);
        $this->ensurePasswordConfirmed($request, $user, 'two_factor_authentication');

        return $user;
    }

    /**
     * @return array{enabled: bool, confirmed: bool, requires_confirmation: bool}
     */
    protected function status(Model $user): array
    {
        $hasSecret = ! blank($user->getAttribute('two_factor_secret'));

        return [
            'enabled' => $hasSecret,
            'confirmed' => $hasSecret && $user->getAttribute('two_factor_confirmed_at') !== null,
            'requires_confirmation' => $this->fortify()->confirmsTwoFactor(),
        ];
    }

    protected function ensureSecret(Model $user): void
    {
        if (blank($user->getAttribute('two_factor_secret'))) {
            throw FlutterBridgeException::twoFactorNotEnabled();
        }
    }

    protected function secret(Model $user): string
    {
        return (string) $this->fortify()->encrypter()->decrypt($user->getAttribute('two_factor_secret'));
    }

    /**
     * @return list<string>
     */
    protected function codesOf(Model $user): array
    {
        if (method_exists($user, 'recoveryCodes')) {
            return array_values((array) $user->recoveryCodes());
        }

        return array_values((array) json_decode((string) $this->fortify()->encrypter()->decrypt($user->getAttribute('two_factor_recovery_codes')), true));
    }
}
