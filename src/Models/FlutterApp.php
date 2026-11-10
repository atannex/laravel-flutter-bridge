<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Models;

use Atannex\LaravelFlutterBridge\Support\Payload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered Flutter client application identified by an app key.
 *
 * @property int $id
 * @property string $name
 * @property string $key
 * @property string $status
 * @property list<string>|null $platforms
 * @property string|null $min_version
 * @property string|null $latest_version
 * @property string|null $update_url
 * @property array<string, mixed>|null $settings
 * @property \Illuminate\Support\Carbon|null $last_connected_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class FlutterApp extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const KEY_PREFIX = 'fba_';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key',
        'status',
        'platforms',
        'min_version',
        'latest_version',
        'update_url',
        'settings',
        'last_connected_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'platforms' => 'array',
        'settings' => 'array',
        'last_connected_at' => 'datetime',
    ];

    /**
     * The table associated with the model.
     */
    public function getTable(): string
    {
        return config('flutter-bridge.apps.table_name', 'flutter_apps');
    }

    /**
     * Connection sessions opened by this app.
     */
    public function connections(): HasMany
    {
        return $this->hasMany(FlutterConnection::class, 'app_id');
    }

    /**
     * Scope for active apps.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Whether the app is allowed to talk to the bridge.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether the app is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Whether the app is allowed on the given platform (null platforms = all).
     */
    public function supportsPlatform(?string $platform): bool
    {
        if ($platform === null || empty($this->platforms)) {
            return true;
        }

        return in_array(strtolower($platform), array_map('strtolower', $this->platforms), true);
    }

    /**
     * Minimum client version, falling back to the global configuration.
     */
    public function effectiveMinVersion(): ?string
    {
        $min = $this->min_version ?? config('flutter-bridge.connections.min_app_version');

        return $min !== null && $min !== '' ? (string) $min : null;
    }

    /**
     * Serialize to the handshake "app" object.
     *
     * @return array<string, mixed>
     */
    public function toBridgeArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'status' => $this->status,
            'min_version' => $this->effectiveMinVersion(),
            'latest_version' => $this->latest_version,
            'update_url' => $this->update_url,
            'platforms' => $this->platforms,
            'settings' => Payload::object($this->settings),
        ];
    }
}
