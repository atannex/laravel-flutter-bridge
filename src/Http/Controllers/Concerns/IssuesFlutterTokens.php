<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers\Concerns;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Services\FlutterTokenManager;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared token issuing logic for the authentication controllers.
 */
trait IssuesFlutterTokens
{
    /**
     * Issue an access / refresh pair bound to the request's device and connection session.
     *
     * @return array{authorization: array<string, mixed>, connection: FlutterConnection|null}
     */
    protected function issueTokens(Request $request, Model $user, string $deviceName, ?string $deviceId = null): array
    {
        $connection = BridgeRequest::connection($request);
        $headerDevice = $request->header('X-Flutter-Device-Id');

        $deviceId = $deviceId
            ?? (is_string($headerDevice) && $headerDevice !== '' ? $headerDevice : null)
            ?? $connection?->device_id;

        $authorization = app(FlutterTokenManager::class)->issue(
            $user,
            $deviceName,
            (array) config('flutter-bridge.auth.token_abilities', ['*']),
            null,
            $connection?->getKey(),
            $deviceId
        );

        return [
            'authorization' => $authorization,
            'connection' => $connection?->refresh(),
        ];
    }

    /**
     * Build the standard authentication payload.
     *
     * @param array<string, mixed> $authorization
     * @return array<string, mixed>
     */
    protected function authPayload(Request $request, ?Model $user, array $authorization, ?FlutterConnection $connection): array
    {
        $payload = [];

        if ($user !== null) {
            $payload['user'] = $user;
        }

        $payload['authorization'] = $authorization;

        if ($connection !== null) {
            $payload['connection'] = $connection->toBridgeArray(BridgeRequest::connectionId($request));
        }

        return $payload;
    }

    /**
     * Abort with 403 feature_disabled when a feature is turned off.
     *
     * @throws FlutterBridgeException
     */
    protected function ensureFeature(string $feature): void
    {
        if (!app('flutter.bridge')->featureEnabled($feature)) {
            throw FlutterBridgeException::featureDisabled($feature);
        }
    }

    /**
     * The configured authenticatable model class.
     *
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        /** @var class-string<Model> $class */
        $class = config('flutter-bridge.auth.user_model', 'App\\Models\\User');

        return $class;
    }

    /**
     * Whether the user model can issue Sanctum tokens.
     */
    protected function supportsTokens(Model $user): bool
    {
        return method_exists($user, 'createToken');
    }
}
