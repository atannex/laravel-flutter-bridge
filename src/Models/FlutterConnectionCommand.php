<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Models;

use Atannex\LaravelFlutterBridge\Support\Payload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A server -> client command queued for a connection session.
 *
 * @property int $id
 * @property string $connection_id
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $expires_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FlutterConnectionCommand extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'connection_id',
        'type',
        'payload',
        'expires_at',
        'delivered_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * The table associated with the model.
     */
    public function getTable(): string
    {
        return config('flutter-bridge.connections.commands_table_name', 'flutter_connection_commands');
    }

    /**
     * The target session.
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(FlutterConnection::class, 'connection_id');
    }

    /**
     * Scope for undelivered, non-expired commands.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('delivered_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Serialize to the wire contract command object.
     *
     * @return array<string, mixed>
     */
    public function toBridgeArray(): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'payload' => Payload::object($this->payload),
            'created_at' => Payload::date($this->created_at),
        ];
    }
}
