<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Events;

use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FlutterConnectionEstablished
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  bool  $resumed  Whether an existing session was resumed instead of created.
     */
    public function __construct(
        public FlutterConnection $connection,
        public bool $resumed = false
    ) {}
}
