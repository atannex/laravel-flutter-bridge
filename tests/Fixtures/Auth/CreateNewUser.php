<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures\Auth;

use Atannex\LaravelFlutterBridge\Tests\Fixtures\TwoFactorUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify-style sign-up: 12-char passwords, "member" role, home_calendar field.
 */
class CreateNewUser implements CreatesNewUsers
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): TwoFactorUser
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'home_calendar' => ['required', 'string', 'max:64'],
        ])->validate();

        /** @var TwoFactorUser $user */
        $user = TwoFactorUser::query()->create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'role' => 'member',
            'home_calendar' => $input['home_calendar'],
        ]);

        return $user;
    }
}
