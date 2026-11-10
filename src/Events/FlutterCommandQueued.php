<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Events;

use Atannex\LaravelFlutterBridge\Broadcasting\BroadcastGate;
use Atannex\LaravelFlutterBridge\Models\FlutterConnectionCommand;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast as "BridgeCommand" on private-flutter.connections.{id}; payload = command object.
 */
class FlutterCommandQueued implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public FlutterConnectionCommand $command
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('flutter.connections.'.$this->command->connection_id)];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'BridgeCommand';
    }

    /**
     * Get the data to broadcast (the command object).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->command->toBridgeArray();
    }

    /**
     * Only broadcast when bridge broadcasting is enabled and a real driver is configured.
     */
    public function broadcastWhen(): bool
    {
        return BroadcastGate::enabled();
    }
}
