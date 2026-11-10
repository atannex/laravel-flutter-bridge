<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Exceptions;

use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Protocol-level failure rendered as an enveloped response carrying meta.error_code.
 */
class FlutterBridgeException extends HttpException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        int $statusCode,
        protected string $errorCode,
        protected mixed $errors = null,
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($statusCode, $message, $previous, $headers);
    }

    public static function appKeyRequired(): self
    {
        return new self('An app key is required.', 403, 'app_key_required');
    }

    public static function invalidAppKey(): self
    {
        return new self('The app key is invalid.', 403, 'invalid_app_key');
    }

    public static function appSuspended(): self
    {
        return new self('This app has been suspended.', 403, 'app_suspended');
    }

    public static function connectionRevoked(): self
    {
        return new self('This connection session has been revoked.', 403, 'connection_revoked');
    }

    public static function connectionNotFound(): self
    {
        return new self('Connection session not found.', 404, 'connection_not_found');
    }

    public static function invalidRefreshToken(): self
    {
        return new self('The refresh token is invalid or expired.', 401, 'invalid_refresh_token');
    }

    public static function refreshTokenReused(): self
    {
        return new self('The refresh token has already been used. All sessions for this device were revoked.', 401, 'refresh_token_reused');
    }

    public static function featureDisabled(string $feature): self
    {
        return new self("The {$feature} feature is disabled.", 403, 'feature_disabled');
    }

    /**
     * A Fortify feature that is off on the server (or Fortify is not installed).
     */
    public static function fortifyFeatureDisabled(string $feature): self
    {
        return new self(__('This feature is not available.'), 404, 'feature_disabled', ['feature' => $feature]);
    }

    public static function passwordConfirmationRequired(): self
    {
        return new self(__('Please confirm your password to continue.'), 423, 'password_confirmation_required');
    }

    /**
     * A wrong, expired or locked emailed code (VerificationCodeBroker result).
     */
    public static function code(string $result): self
    {
        return match ($result) {
            'code_expired' => new self(__('This code has expired. Please request a new one.'), 422, 'code_expired', ['code' => [__('This code has expired. Please request a new one.')]]),
            'code_locked' => new self(__('Too many wrong codes. Please request a new one.'), 423, 'code_locked', ['code' => [__('Too many wrong codes. Please request a new one.')]]),
            default => new self(__('This code is invalid.'), 422, 'code_invalid', ['code' => [__('This code is invalid.')]]),
        };
    }

    public static function alreadyVerified(): self
    {
        return new self(__('Your email address is already verified.'), 409, 'already_verified');
    }

    public static function twoFactorNotEnabled(): self
    {
        return new self(__('Two-factor authentication has not been enabled.'), 404, 'two_factor_not_enabled');
    }

    public static function twoFactorInvalid(string $message): self
    {
        return new self($message, 422, 'two_factor_invalid', ['code' => [$message]]);
    }

    public static function resetTokenInvalid(?string $message = null): self
    {
        $message ??= __('This password reset token is invalid.');

        return new self($message, 422, 'reset_token_invalid', ['email' => [$message]]);
    }

    public static function passwordInvalid(): self
    {
        $message = __('The provided password was incorrect.');

        return new self($message, 422, 'password_invalid', ['password' => [$message]]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function upgradeRequired(array $details): self
    {
        return new self('A newer version of the app is required.', 426, 'upgrade_required', $details);
    }

    /**
     * The machine-readable meta.error_code.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Additional error payload.
     */
    public function getErrors(): mixed
    {
        return $this->errors;
    }

    /**
     * Render the exception as an enveloped bridge response.
     */
    public function render(): JsonResponse
    {
        $response = FlutterResponse::error(
            $this->getMessage(),
            $this->getStatusCode(),
            $this->errors,
            [],
            $this->errorCode
        );

        foreach ($this->getHeaders() as $name => $value) {
            $response->headers->set((string) $name, is_array($value) ? $value : (string) $value);
        }

        return $response;
    }
}
