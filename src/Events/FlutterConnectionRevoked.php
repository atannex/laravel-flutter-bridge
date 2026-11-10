<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Events;

use Atannex\LaravelFlutterBridge\Broadcasting\BroadcastGate;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Support\Payload;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast as "ConnectionRevoked" on private-flutter.connections.{id}.
 */
class FlutterConnectionRevoked implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public FlutterConnection $connection,
        public ?string $reason = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('flutter.connections.'.$this->connection->getKey())];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'ConnectionRevoked';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'connection_id' => (string) $this->connection->getKey(),
            'reason' => $this->reason,
            'revoked_at' => Payload::date($this->connection->revoked_at),
        ];
    }

    /**
     * Only broadcast when bridge broadcasting is enabled and a real driver is configured.
     */
    public function broadcastWhen(): bool
    {
        return BroadcastGate::enabled();
    }
}
