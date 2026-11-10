<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hashed, rotating refresh token bound to an access token, device and session.
 *
 * @property int $id
 * @property string|null $user_type
 * @property int $user_id
 * @property string $token
 * @property string $family
 * @property int|null $access_token_id
 * @property string|null $connection_id
 * @property string|null $device_id
 * @property string|null $device_name
 * @property list<string>|null $abilities
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $replaced_by_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class FlutterRefreshToken extends Model
{
    public const REASON_ROTATED = 'rotated';

    public const REASON_LOGOUT = 'logout';

    public const REASON_CONNECTION_REVOKED = 'connection_revoked';

    public const REASON_REUSE_DETECTED = 'reuse_detected';

    public const REASON_SINGLE_SESSION = 'single_session';

    public const REASON_REVOKED = 'revoked';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_type',
        'user_id',
        'token',
        'family',
        'access_token_id',
        'connection_id',
        'device_id',
        'device_name',
        'abilities',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'revoked_reason',
        'replaced_by_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'abilities' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * The table associated with the model.
     */
    public function getTable(): string
    {
        return config('flutter-bridge.refresh_tokens.table_name', 'flutter_refresh_tokens');
    }

    /**
     * Hash a plain refresh token for storage / lookup.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * The session the token is bound to.
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(FlutterConnection::class, 'connection_id');
    }

    /**
     * Resolve the owning user model.
     */
    public function resolveUser(): ?Model
    {
        $class = $this->user_type ? Model::getActualClassNameForMorph($this->user_type) : null;

        if ($class === null || !class_exists($class)) {
            $class = (string) config('flutter-bridge.auth.user_model', 'App\\Models\\User');
        }

        if (!class_exists($class)) {
            return null;
        }

        /** @var Model|null $user */
        $user = $class::query()->find($this->user_id);

        return $user;
    }

    /**
     * Scope for tokens that are neither revoked nor expired.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Whether the token has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Whether the token has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
