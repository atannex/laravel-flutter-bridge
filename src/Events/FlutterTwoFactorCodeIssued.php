<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a 2FA one-time code is generated. Listen to it to deliver the
 * code by mail, SMS or push.
 */
class FlutterTwoFactorCodeIssued
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Model $user,
        public string $code,
        public string $twoFactorToken,
        public int $expiresIn
    ) {}
}
