<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

interface HasFlutterTokensInterface
{
    /**
     * Create a new access / refresh token pair for a Flutter client device,
     * optionally bound to a connection session.
     *
     * @param list<string> $abilities
     * @return array{
     *     token_type: string,
     *     access_token: string,
     *     refresh_token: string,
     *     expires_in: int|null,
     *     expires_at: string|null,
     *     refresh_expires_at: string|null
     * }
     */
    public function createFlutterToken(
        string $deviceName = 'flutter-client',
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null,
        ?string $connectionId = null,
        ?string $deviceId = null
    ): array;

    /**
     * Revoke the current access token and its refresh token(s).
     */
    public function revokeCurrentFlutterToken(): bool;

    /**
     * Revoke all access and refresh tokens for this user.
     */
    public function revokeAllFlutterTokens(): int;

    /**
     * Refresh tokens issued to this user.
     */
    public function flutterRefreshTokens(): HasMany;

    /**
     * Connection sessions linked to this user.
     */
    public function flutterConnections(): HasMany;
}
