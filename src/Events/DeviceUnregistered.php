<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceUnregistered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $deviceId,
        public ?int $userId = null
    ) {}
}
