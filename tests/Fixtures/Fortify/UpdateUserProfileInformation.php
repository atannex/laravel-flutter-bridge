<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Fixtures\Fortify;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Fortify's stub: a new email must be verified again.
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(FortifyUser $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'home_calendar' => ['nullable', 'string', 'max:64'],
        ])->validateWithBag('updateProfileInformation');

        $changedEmail = $input['email'] !== $user->email;

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'home_calendar' => $input['home_calendar'] ?? $user->home_calendar,
            'email_verified_at' => $changedEmail ? null : $user->email_verified_at,
        ])->save();

        if ($changedEmail) {
            $user->sendEmailVerificationNotification();
        }
    }
}
