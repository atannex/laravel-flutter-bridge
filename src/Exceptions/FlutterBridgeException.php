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
