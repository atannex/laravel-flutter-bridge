<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Models;

use Atannex\LaravelFlutterBridge\Support\Payload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A live connection session between one device / app install and the server.
 *
 * @property string $id
 * @property int|null $app_id
 * @property int|null $user_id
 * @property string $device_id
 * @property string $platform
 * @property string|null $device_name
 * @property string|null $app_version
 * @property string|null $os_version
 * @property string|null $bridge_version
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $status
 * @property string|null $state
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $disconnected_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FlutterApp|null $flutterApp
 */
class FlutterConnection extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_REVOKED = 'revoked';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'app_id',
        'user_id',
        'device_id',
        'platform',
        'device_name',
        'app_version',
        'os_version',
        'bridge_version',
        'ip_address',
        'user_agent',
        'status',
        'state',
        'metadata',
        'connected_at',
        'last_seen_at',
        'disconnected_at',
        'revoked_at',
        'revoked_reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'connected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * The table associated with the model.
     */
    public function getTable(): string
    {
        return config('flutter-bridge.connections.table_name', 'flutter_connections');
    }

    /**
     * The client app that opened the session.
     */
    public function flutterApp(): BelongsTo
    {
        return $this->belongsTo(FlutterApp::class, 'app_id');
    }

    /**
     * The user linked to the session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('flutter-bridge.auth.user_model', 'App\\Models\\User'), 'user_id');
    }

    /**
     * Commands queued for this session.
     */
    public function commands(): HasMany
    {
        return $this->hasMany(FlutterConnectionCommand::class, 'connection_id');
    }

    /**
     * Refresh tokens bound to this session.
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(FlutterRefreshToken::class, 'connection_id');
    }

    /**
     * Scope for non-revoked sessions.
     */
    public function scopeNotRevoked(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_REVOKED);
    }

    /**
     * Scope for sessions with status "active".
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope for sessions that are active and have been seen within the timeout.
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('last_seen_at', '>=', now()->subSeconds(self::timeout()));
    }

    /**
     * Scope for sessions belonging to a user.
     */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Seconds without heartbeat after which a session is offline.
     */
    public static function timeout(): int
    {
        return max(1, (int) config('flutter-bridge.connections.timeout', 90));
    }

    /**
     * Whether the session has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /**
     * Whether the session is active and has been seen within the timeout.
     */
    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds(self::timeout()));
    }

    /**
     * Whether the session belongs to the given user id.
     */
    public function belongsToUser(int|string|null $userId): bool
    {
        return $userId !== null && $this->user_id !== null && (string) $this->user_id === (string) $userId;
    }

    /**
     * Serialize to the wire contract Connection object.
     *
     * @return array<string, mixed>
     */
    public function toBridgeArray(?string $currentId = null): array
    {
        return [
            'id' => (string) $this->id,
            'app_key' => $this->app_id !== null ? $this->flutterApp?->key : null,
            'user_id' => $this->user_id,
            'device_id' => $this->device_id,
            'platform' => $this->platform,
            'device_name' => $this->device_name,
            'app_version' => $this->app_version,
            'os_version' => $this->os_version,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'status' => $this->status,
            'is_online' => $this->isOnline(),
            'is_current' => $currentId !== null && $currentId === (string) $this->id,
            'connected_at' => Payload::date($this->connected_at),
            'last_seen_at' => Payload::date($this->last_seen_at),
            'disconnected_at' => Payload::date($this->disconnected_at),
            'revoked_at' => Payload::date($this->revoked_at),
            'metadata' => Payload::object($this->metadata),
        ];
    }
}
