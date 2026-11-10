<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify;

use Atannex\LaravelFlutterBridge\Notifications\Fortify\ResetPasswordCodeNotification;

class CustomResetNotification extends ResetPasswordCodeNotification
{
    protected function subjectLine(): string
    {
        return 'Custom :app code';
    }
}
