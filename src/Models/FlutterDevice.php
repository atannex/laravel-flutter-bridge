<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $device_id
 * @property string|null $fcm_token
 * @property string $platform
 * @property string|null $device_name
 * @property string|null $os_version
 * @property string|null $app_version
 * @property array|null $metadata
 * @property Carbon|null $last_active_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FlutterDevice extends Model
{
    /**
     * The table associated with the model.
     */
    public function getTable(): string
    {
        return config('flutter-bridge.devices.table_name', 'flutter_devices');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'device_id',
        'fcm_token',
        'platform',
        'device_name',
        'os_version',
        'app_version',
        'metadata',
        'last_active_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'last_active_at' => 'datetime',
    ];

    /**
     * Get the user that owns the device.
     */
    public function user(): BelongsTo
    {
        $userModel = config('flutter-bridge.auth.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * Scope for filtering by platform.
     */
    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', strtolower($platform));
    }

    /**
     * Scope for filtering devices that have an active FCM token.
     */
    public function scopeWithFcmToken(Builder $query): Builder
    {
        return $query->whereNotNull('fcm_token')->where('fcm_token', '!=', '');
    }

    /**
     * Touch last active timestamp.
     */
    public function touchLastActive(): bool
    {
        $this->last_active_at = now();

        return $this->save();
    }
}
