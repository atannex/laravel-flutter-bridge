<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures;

use Atannex\LaravelFlutterBridge\Traits\BroadcastsToFlutter;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Example broadcast event consumed by Flutter clients.
 */
class OrderShipped implements ShouldBroadcastNow
{
    use BroadcastsToFlutter;

    public function __construct(
        public int $userId,
        public int $orderId,
        public string $room = 'warehouse'
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            $this->privateUserChannel($this->userId),
            $this->presenceRoomChannel($this->room),
            $this->publicFlutterChannel('orders'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->formatFlutterPayload(['order_id' => $this->orderId]);
    }
}
