<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A user model with only Sanctum's HasApiTokens (no bridge traits).
 */
class SanctumOnlyUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];
}
