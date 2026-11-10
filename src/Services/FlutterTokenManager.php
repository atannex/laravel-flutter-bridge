<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Services;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Atannex\LaravelFlutterBridge\Support\Payload;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Issues Sanctum access tokens paired with hashed, rotating refresh tokens that
 * are bound to a device and (optionally) a connection session.
 */
class FlutterTokenManager
{
    /**
     * Issue a new access / refresh token pair.
     *
     * @param  list<string>  $abilities
     * @return array{token_type: string, access_token: string, refresh_token: string, expires_in: int|null, expires_at: string|null, refresh_expires_at: string|null}
     */
    public function issue(
        Model $user,
        string $deviceName = 'flutter-client',
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null,
        ?string $connectionId = null,
        ?string $deviceId = null
    ): array {
        $connection = $this->bindableConnection($connectionId);
        $deviceId = $deviceId ?? $connection?->device_id;

        if ($deviceId !== null && config('flutter-bridge.auth.single_session_per_device', true)) {
            $this->revokeForDevice($user, $deviceId, FlutterRefreshToken::REASON_SINGLE_SESSION);
        }

        $pair = $this->createPair($user, $deviceName, $abilities, $expiresAt, $connection, $deviceId, (string) Str::uuid());
        unset($pair['_record_id']);

        return $pair;
    }

    /**
     * Rotate a refresh token: revoke it, delete its access token and issue a new pair.
     *
     * Replaying an already rotated token revokes every token of that user + device.
     *
     * @return array{user: Model, authorization: array<string, mixed>, connection: FlutterConnection|null}
     *
     * @throws FlutterBridgeException
     */
    public function rotate(string $plainRefreshToken, ?string $connectionId = null): array
    {
        /** @var FlutterRefreshToken|null $record */
        $record = FlutterRefreshToken::query()
            ->where('token', FlutterRefreshToken::hashToken($plainRefreshToken))
            ->first();

        if ($record === null) {
            throw FlutterBridgeException::invalidRefreshToken();
        }

        if ($record->isRevoked()) {
            if ($record->revoked_reason === FlutterRefreshToken::REASON_ROTATED) {
                $this->handleReuse($record);

                throw FlutterBridgeException::refreshTokenReused();
            }

            throw FlutterBridgeException::invalidRefreshToken();
        }

        if ($record->isExpired()) {
            throw FlutterBridgeException::invalidRefreshToken();
        }

        $user = $record->resolveUser();

        if ($user === null) {
            throw FlutterBridgeException::invalidRefreshToken();
        }

        // Atomically claim the token; a concurrent rotation of the same token counts as reuse.
        $claimed = FlutterRefreshToken::query()
            ->whereKey($record->getKey())
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => FlutterRefreshToken::REASON_ROTATED,
                'last_used_at' => now(),
            ]);

        if ($claimed === 0) {
            $this->handleReuse($record);

            throw FlutterBridgeException::refreshTokenReused();
        }

        $connection = $this->bindableConnection($connectionId ?? $record->connection_id);

        $authorization = DB::transaction(function () use ($record, $user, $connection) {
            if ($record->access_token_id !== null) {
                $this->deleteAccessTokens([$record->access_token_id]);
            }

            $authorization = $this->createPair(
                $user,
                (string) ($record->device_name ?? 'flutter-client'),
                $record->abilities ?? ['*'],
                null,
                $connection,
                $record->device_id ?? $connection?->device_id,
                $record->family
            );

            FlutterRefreshToken::query()
                ->whereKey($record->getKey())
                ->update(['replaced_by_id' => $authorization['_record_id']]);

            return $authorization;
        });

        unset($authorization['_record_id']);

        return [
            'user' => $user,
            'authorization' => $authorization,
            'connection' => $connection,
        ];
    }

    /**
     * Revoke the user's current access token and the refresh token(s) paired with it.
     */
    public function revokeCurrent(Model $user): bool
    {
        if (! method_exists($user, 'currentAccessToken')) {
            return false;
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof Model) {
            return false;
        }

        $this->revokeWhere(
            FlutterRefreshToken::query()->where('access_token_id', $token->getKey()),
            FlutterRefreshToken::REASON_LOGOUT,
            false
        );

        $token->delete();

        return true;
    }

    /**
     * Revoke every refresh token bound to a connection and delete their access tokens.
     */
    public function revokeForConnection(FlutterConnection|string $connection, string $reason = FlutterRefreshToken::REASON_CONNECTION_REVOKED): int
    {
        $id = $connection instanceof FlutterConnection ? (string) $connection->getKey() : $connection;

        return $this->revokeWhere(FlutterRefreshToken::query()->where('connection_id', $id), $reason);
    }

    /**
     * Revoke every refresh token of a user + device and delete their access tokens.
     */
    public function revokeForDevice(Model $user, string $deviceId, string $reason = FlutterRefreshToken::REASON_REVOKED): int
    {
        return $this->revokeWhere(
            $this->userTokens($user)->where('device_id', $deviceId),
            $reason
        );
    }

    /**
     * Revoke every refresh token and access token of a user.
     */
    public function revokeAllForUser(Model $user): int
    {
        $count = $this->revokeWhere($this->userTokens($user), FlutterRefreshToken::REASON_REVOKED);

        if (method_exists($user, 'tokens')) {
            $count = max($count, (int) $user->tokens()->delete());
        }

        return $count;
    }

    /**
     * Delete Sanctum personal access tokens by id.
     *
     * @param  array<int, int|string|null>  $ids
     */
    public function deleteAccessTokens(array $ids): int
    {
        $ids = array_values(array_filter($ids, fn ($id) => $id !== null));
        $model = $this->accessTokenModel();

        if ($ids === [] || $model === null) {
            return 0;
        }

        return (int) $model::query()->whereKey($ids)->delete();
    }

    /**
     * Create the Sanctum token and the hashed refresh token record.
     *
     * @param  list<string>  $abilities
     * @return array<string, mixed>
     */
    protected function createPair(
        Model $user,
        string $deviceName,
        array $abilities,
        ?DateTimeInterface $expiresAt,
        ?FlutterConnection $connection,
        ?string $deviceId,
        string $family
    ): array {
        if (! method_exists($user, 'createToken')) {
            throw new LogicException(sprintf(
                'User model [%s] must use Laravel\\Sanctum\\HasApiTokens to issue Flutter tokens.',
                $user::class
            ));
        }

        $prefix = (string) config('flutter-bridge.auth.token_name_prefix', 'flutter-app');
        $accessTtl = config('flutter-bridge.auth.access_token_ttl');
        $refreshTtl = config('flutter-bridge.auth.refresh_token_ttl');

        $expiresAt = $expiresAt ?? ($accessTtl ? now()->addMinutes((int) $accessTtl) : null);
        $refreshExpiresAt = $refreshTtl ? now()->addMinutes((int) $refreshTtl) : now()->addDays(30);

        $newToken = $user->createToken("{$prefix}:{$deviceName}", $abilities, $expiresAt);
        $plainRefresh = Str::random(80);

        /** @var FlutterRefreshToken $record */
        $record = FlutterRefreshToken::query()->create([
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
            'token' => FlutterRefreshToken::hashToken($plainRefresh),
            'family' => $family,
            'access_token_id' => $newToken->accessToken->getKey(),
            'connection_id' => $connection?->getKey(),
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'abilities' => $abilities,
            'expires_at' => $refreshExpiresAt,
        ]);

        if ($connection !== null && ! $connection->belongsToUser($user->getKey())) {
            $connection->forceFill(['user_id' => $user->getKey()])->save();
        }

        return [
            'token_type' => 'Bearer',
            'access_token' => $newToken->plainTextToken,
            'refresh_token' => $plainRefresh,
            'expires_in' => $expiresAt !== null ? max(0, $expiresAt->getTimestamp() - now()->getTimestamp()) : null,
            'expires_at' => Payload::date($expiresAt),
            'refresh_expires_at' => Payload::date($refreshExpiresAt),
            '_record_id' => $record->getKey(),
        ];
    }

    /**
     * Revoke the family of a replayed token (user + device, or the rotation family).
     */
    protected function handleReuse(FlutterRefreshToken $record): void
    {
        $query = FlutterRefreshToken::query()->where('user_id', $record->user_id);

        if ($record->user_type !== null) {
            $query->where('user_type', $record->user_type);
        }

        if ($record->device_id !== null) {
            $query->where(function ($q) use ($record) {
                $q->where('device_id', $record->device_id)->orWhere('family', $record->family);
            });
        } else {
            $query->where('family', $record->family);
        }

        $this->revokeWhere($query, FlutterRefreshToken::REASON_REUSE_DETECTED);
    }

    /**
     * Revoke the matching refresh tokens and delete their access tokens.
     *
     * @param  Builder<FlutterRefreshToken>  $query
     */
    protected function revokeWhere($query, string $reason, bool $deleteAccessTokens = true): int
    {
        $tokens = (clone $query)->get(['id', 'access_token_id', 'revoked_at']);

        if ($deleteAccessTokens) {
            $this->deleteAccessTokens($tokens->pluck('access_token_id')->filter()->all());
        }

        $active = $tokens->whereNull('revoked_at')->pluck('id')->all();

        if ($active === []) {
            return 0;
        }

        return (int) FlutterRefreshToken::query()->whereKey($active)->update([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }

    /**
     * Refresh tokens query scoped to a user.
     *
     * @return Builder<FlutterRefreshToken>
     */
    protected function userTokens(Model $user)
    {
        return FlutterRefreshToken::query()
            ->where('user_id', $user->getKey())
            ->where(function ($q) use ($user) {
                $q->where('user_type', $user->getMorphClass())->orWhereNull('user_type');
            });
    }

    /**
     * Resolve a connection that tokens may be bound to (exists and not revoked).
     */
    protected function bindableConnection(?string $connectionId): ?FlutterConnection
    {
        if ($connectionId === null || $connectionId === '' || ! config('flutter-bridge.connections.enabled', true)) {
            return null;
        }

        /** @var FlutterConnection|null $connection */
        $connection = FlutterConnection::query()->find($connectionId);

        return $connection !== null && ! $connection->isRevoked() ? $connection : null;
    }

    /**
     * The Sanctum personal access token model class, when Sanctum is installed.
     *
     * @return class-string<Model>|null
     */
    protected function accessTokenModel(): ?string
    {
        $sanctum = 'Laravel\\Sanctum\\Sanctum';

        if (! class_exists($sanctum)) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = $sanctum::personalAccessTokenModel();

        return $model;
    }
}
