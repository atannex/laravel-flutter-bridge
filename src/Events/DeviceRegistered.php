<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Events;

use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceRegistered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public FlutterDevice $device
    ) {}
}
