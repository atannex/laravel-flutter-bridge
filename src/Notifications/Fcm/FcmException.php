<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Notifications\Fcm;

use RuntimeException;

/**
 * Raised for unusable FCM credentials or a failed OAuth2 token exchange.
 */
class FcmException extends RuntimeException
{
}
