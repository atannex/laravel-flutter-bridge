<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures;

use Atannex\LaravelFlutterBridge\Contracts\HasFlutterDevicesInterface;
use Atannex\LaravelFlutterBridge\Contracts\HasFlutterTokensInterface;
use Atannex\LaravelFlutterBridge\Traits\HasFlutterDevices;
use Atannex\LaravelFlutterBridge\Traits\HasFlutterTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements HasFlutterTokensInterface, HasFlutterDevicesInterface
{
    use HasApiTokens, HasFlutterTokens, HasFlutterDevices;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];
}
