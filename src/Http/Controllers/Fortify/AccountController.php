<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers\Fortify;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Http\Controllers\Concerns\InteractsWithFortify;
use Atannex\LaravelFlutterBridge\Models\FlutterRefreshToken;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Services\FlutterTokenManager;
use Atannex\LaravelFlutterBridge\Services\PasswordConfirmation;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;
use LogicException;

/**
 * Profile, password and password confirmation through Fortify's actions.
 */
class AccountController extends Controller
{
    use InteractsWithFortify;

    protected const UPDATES_PROFILE = 'Laravel\\Fortify\\Contracts\\UpdatesUserProfileInformation';

    protected const UPDATES_PASSWORDS = 'Laravel\\Fortify\\Contracts\\UpdatesUserPasswords';

    protected const CONFIRM_PASSWORD = 'Laravel\\Fortify\\Actions\\ConfirmPassword';

    protected const PASSWORD_UPDATED_EVENT = 'Laravel\\Fortify\\Events\\PasswordUpdatedViaController';

    /**
     * PUT auth/user/profile-information.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $this->fortify()->ensureEnabled('update_profile_information');
        $user = $this->currentUser($request);

        $this->fortify()->canonicalize($request, $this->fortify()->username());

        $action = $this->action(self::UPDATES_PROFILE, 'Fortify::updateUserProfileInformationUsing()');
        $emailField = $this->fortify()->email();
        $emailBefore = (string) $user->getAttribute($emailField);

        $this->fortify()->actAs($user);
        $action->update($user, $request->all());

        $user->refresh();

        $emailChanged = strcasecmp($emailBefore, (string) $user->getAttribute($emailField)) !== 0;
        $verificationRequired = $this->needsEmailVerification($user);

        if ($emailChanged && $verificationRequired
            && $this->fortify()->enabled('email_verification')
            && config('flutter-bridge.fortify.verification.send_code_on_email_change', true)) {
            $this->sendVerificationCode($user);
        }

        return FlutterResponse::success([
            'user' => $this->presentUser($user),
            'email_verification_required' => $verificationRequired,
        ], __('Your profile has been updated.'));
    }

    /**
     * PUT auth/user/password; `revoke_other_sessions` signs every other device out.
     */
    public function updatePassword(Request $request, FlutterTokenManager $tokens): JsonResponse
    {
        $this->fortify()->ensureEnabled('update_passwords');
        $user = $this->currentUser($request);

        $request->validate(['revoke_other_sessions' => ['sometimes', 'boolean']]);

        $action = $this->action(self::UPDATES_PASSWORDS, 'Fortify::updateUserPasswordsUsing()');

        // Rules such as current_password:web check Fortify's guard.
        $this->fortify()->actAs($user);
        $action->update($user, $request->all());

        // As Fortify's PasswordController: pending reset tokens die with the old password.
        $broker = Password::broker($this->fortify()->passwordBroker());

        if ($broker instanceof PasswordBroker && $user instanceof CanResetPassword) {
            $broker->deleteToken($user);
        }

        if (class_exists(self::PASSWORD_UPDATED_EVENT)) {
            event(app(self::PASSWORD_UPDATED_EVENT, ['user' => $user]));
        }

        $revoked = 0;

        if ($request->boolean('revoke_other_sessions')) {
            $revoked = $this->revokeOtherSessions($request, $user, $tokens);
        }

        return FlutterResponse::success([
            'updated' => true,
            'revoked_sessions' => $revoked,
        ], __('Your password has been updated.'));
    }

    /**
     * POST auth/user/confirm-password: confirms the current token for auth.password_timeout seconds.
     */
    public function confirmPassword(Request $request, PasswordConfirmation $confirmation): JsonResponse
    {
        $this->fortify()->ensureActive();
        $user = $this->currentUser($request);

        $request->validate(['password' => ['required', 'string']]);

        $confirmed = class_exists(self::CONFIRM_PASSWORD)
            ? (bool) app(self::CONFIRM_PASSWORD)($this->fortify()->guard(), $user, (string) $request->input('password'))
            : $this->fortify()->guard()->validate([
                $this->fortify()->username() => $user->getAttribute($this->fortify()->username()),
                'password' => (string) $request->input('password'),
            ]);

        if (! $confirmed) {
            throw FlutterBridgeException::passwordInvalid();
        }

        return FlutterResponse::success([
            'confirmed' => true,
            'expires_in' => $confirmation->confirm($request, $user),
        ], __('Password confirmed.'));
    }

    /**
     * GET auth/user/confirmed-password-status.
     */
    public function confirmedPasswordStatus(Request $request, PasswordConfirmation $confirmation): JsonResponse
    {
        $this->fortify()->ensureActive();
        $user = $this->currentUser($request);
        $remaining = $confirmation->remaining($request, $user);

        return FlutterResponse::success([
            'confirmed' => $remaining > 0,
            'expires_in' => $remaining,
        ], __('Password confirmation status.'));
    }

    /**
     * Revoke every bridge token and connection of the user except the current ones.
     */
    protected function revokeOtherSessions(Request $request, Model $user, FlutterTokenManager $tokens): int
    {
        $current = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $currentId = $current instanceof Model ? $current->getKey() : null;

        $others = FlutterRefreshToken::query()
            ->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->where('user_type', $user->getMorphClass())->orWhereNull('user_type'))
            ->when($currentId !== null, fn ($q) => $q->where(fn ($q) => $q->where('access_token_id', '!=', $currentId)->orWhereNull('access_token_id')))
            ->whereNull('revoked_at')
            ->get(['id', 'access_token_id']);

        $tokens->deleteAccessTokens($others->pluck('access_token_id')->all());

        $revoked = FlutterRefreshToken::query()->whereKey($others->pluck('id')->all())->update([
            'revoked_at' => now(),
            'revoked_reason' => FlutterRefreshToken::REASON_REVOKED,
        ]);

        // Personal access tokens without a bridge refresh token (e.g. other API clients).
        if (method_exists($user, 'tokens')) {
            $revoked += (int) $user->tokens()
                ->when($currentId !== null, fn ($q) => $q->whereKeyNot($currentId))
                ->delete();
        }

        if (config('flutter-bridge.connections.enabled', true)) {
            app(FlutterConnectionManager::class)->revokeAllForUser(
                $user->getKey(),
                BridgeRequest::connectionId($request),
                'password_changed'
            );
        }

        return $revoked;
    }

    /**
     * Resolve a bound Fortify action contract.
     */
    protected function action(string $contract, string $hint): object
    {
        if (! app()->bound($contract)) {
            throw new LogicException("Register {$hint} to use this endpoint over the bridge.");
        }

        return app($contract);
    }
}
