<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * FCM reported a push token as dead (unregistered / invalid); the matching
 * FlutterDevice row (if any) had its fcm_token cleared.
 */
class FlutterPushTokenInvalidated
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $token,
        public ?int $deviceRowId = null
    ) {}
}
