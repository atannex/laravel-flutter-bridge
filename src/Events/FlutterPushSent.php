<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Notifications\Notification;

/**
 * Summary of one flutter_push channel delivery.
 */
class FlutterPushSent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * @param list<string> $invalidatedTokens
     */
    public function __construct(
        public mixed $notifiable,
        public Notification $notification,
        public int $sent,
        public int $failed,
        public int $invalidated,
        public array $invalidatedTokens = []
    ) {}
}
