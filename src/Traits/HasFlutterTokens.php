<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Traits;

use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Atannex\LaravelFlutterBridge\Services\FlutterTokenManager;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trait providing Flutter token creation and lifecycle management.
 *
 * Requires Laravel Sanctum's HasApiTokens on the same model. Tokens are issued
 * by FlutterTokenManager, which persists hashed, rotating refresh tokens.
 */
trait HasFlutterTokens
{
    /**
     * Create a new access / refresh token pair for a Flutter client device,
     * optionally bound to a connection session.
     *
     * @param  list<string>  $abilities
     * @return array{token_type: string, access_token: string, refresh_token: string, expires_in: int|null, expires_at: string|null, refresh_expires_at: string|null}
     */
    public function createFlutterToken(
        string $deviceName = 'flutter-client',
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null,
        ?string $connectionId = null,
        ?string $deviceId = null
    ): array {
        return app(FlutterTokenManager::class)->issue(
            $this,
            $deviceName,
            $abilities,
            $expiresAt,
            $connectionId,
            $deviceId
        );
    }

    /**
     * Revoke the current access token and its refresh token(s).
     */
    public function revokeCurrentFlutterToken(): bool
    {
        return app(FlutterTokenManager::class)->revokeCurrent($this);
    }

    /**
     * Revoke all access and refresh tokens for this user.
     */
    public function revokeAllFlutterTokens(): int
    {
        return app(FlutterTokenManager::class)->revokeAllForUser($this);
    }

    /**
     * Refresh tokens issued to this user.
     */
    public function flutterRefreshTokens(): HasMany
    {
        return $this->hasMany(FlutterRefreshToken::class, 'user_id')
            ->where(function ($query) {
                $query->where('user_type', $this->getMorphClass())->orWhereNull('user_type');
            });
    }

    /**
     * Connection sessions linked to this user.
     */
    public function flutterConnections(): HasMany
    {
        return $this->hasMany(FlutterConnection::class, 'user_id');
    }
}
