<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Events;

use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FlutterConnectionDisconnected
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public FlutterConnection $connection
    ) {}
}
