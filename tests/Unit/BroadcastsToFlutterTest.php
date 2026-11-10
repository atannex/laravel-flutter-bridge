<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Unit;

use Atannex\LaravelFlutterBridge\Tests\Fixtures\OrderShipped;
use Atannex\LaravelFlutterBridge\Tests\TestCase;

class BroadcastsToFlutterTest extends TestCase
{
    public function test_event_uses_short_name_and_flutter_channel_conventions(): void
    {
        $event = new OrderShipped(userId: 7, orderId: 42);

        $this->assertSame('OrderShipped', $event->broadcastAs());

        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
        $this->assertSame(['private-users.7', 'presence-presence.warehouse', 'orders'], $channels);

        $payload = $event->broadcastWith();
        $this->assertSame(42, $payload['order_id']);
        $this->assertSame('OrderShipped', $payload['_event']);
        $this->assertArrayHasKey('_timestamp', $payload);
    }
}
