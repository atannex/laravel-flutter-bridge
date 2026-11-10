<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures;

use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * User with Fortify authenticator-app columns, a role and a home calendar.
 */
class TwoFactorUser extends User
{
    use TwoFactorAuthenticatable;

    protected $fillable = ['name', 'email', 'password', 'role', 'home_calendar'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $casts = [
        'two_factor_confirmed_at' => 'datetime',
    ];
}
