<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures\Auth;

use Atannex\LaravelFlutterBridge\Tests\Fixtures\TwoFactorUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Custom sign-in: accepts a `login` field (email) and refuses suspended accounts.
 */
class AuthenticateUser
{
    public function __invoke(Request $request): ?TwoFactorUser
    {
        /** @var TwoFactorUser|null $user */
        $user = TwoFactorUser::query()
            ->where('email', (string) ($request->input('login') ?? $request->input('email')))
            ->first();

        if ($user === null || $user->getAttribute('role') === 'suspended'
            || !Hash::check((string) $request->input('password'), $user->getAuthPassword())) {
            return null;
        }

        return $user;
    }
}
