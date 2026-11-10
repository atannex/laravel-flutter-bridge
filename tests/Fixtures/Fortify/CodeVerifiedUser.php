<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify;

use Atannex\LaravelFlutterBridge\Traits\VerifiesEmailWithFlutterCodes;

/**
 * A Fortify user that gets one verification email (the bridge's code) on app requests.
 */
class CodeVerifiedUser extends FortifyUser
{
    use VerifiesEmailWithFlutterCodes;
}
