<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Traits;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Trait for Laravel Broadcast Events intended for consumption by Flutter clients.
 */
trait BroadcastsToFlutter
{
    /**
     * Get the default broadcast event name (without namespace clutter for clean Flutter listening).
     */
    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    /**
     * Helper to create a private user broadcast channel.
     */
    protected function privateUserChannel(int|string $userId): PrivateChannel
    {
        return new PrivateChannel("users.{$userId}");
    }

    /**
     * Helper to create a presence room channel.
     */
    protected function presenceRoomChannel(string $roomName): PresenceChannel
    {
        return new PresenceChannel("presence.{$roomName}");
    }

    /**
     * Helper to create a public channel.
     */
    protected function publicFlutterChannel(string $channelName): Channel
    {
        return new Channel($channelName);
    }

    /**
     * Standardized broadcast payload wrapping.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function formatFlutterPayload(array $data = []): array
    {
        return array_merge($data, [
            '_event' => class_basename(static::class),
            '_timestamp' => now()->toIso8601String(),
        ]);
    }
}
