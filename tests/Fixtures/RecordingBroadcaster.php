<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Str;

/**
 * Pusher-like broadcaster that signs channel auth and records broadcasts.
 */
class RecordingBroadcaster extends Broadcaster
{
    /**
     * @var list<array{channels: array<int, string>, event: string, payload: array<string, mixed>}>
     */
    public static array $broadcasts = [];

    public function auth($request)
    {
        $channel = Str::startsWith($request->channel_name, ['private-', 'presence-'])
            ? Str::after($request->channel_name, '-')
            : $request->channel_name;

        return parent::verifyUserCanAccessChannel($request, $channel);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return [
            'auth' => 'test-key:' . hash_hmac('sha256', $request->socket_id . ':' . $request->channel_name, 'secret'),
        ];
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        static::$broadcasts[] = [
            'channels' => array_map('strval', $channels),
            'event' => $event,
            'payload' => $payload,
        ];
    }
}
