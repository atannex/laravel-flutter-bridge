# Laravel Fortify over the bridge (Bridge 2.2)

Everything a Fortify web app can do with its session (sign up, sign in,
two-step challenge, forgot / reset password, email verification, profile and
password updates, password confirmation, authenticator-app management,
recovery codes, passkey management) available to the Flutter app with bearer
tokens, **through Fortify's own actions, rules, events and settings**.

Wire protocol stays `2.0.0`; every endpoint below is additive. Envelope and
`meta.error_code` exactly as in CONNECTIONS.md §3.

## 1. Principles

1. **Fortify is the source of truth.** When `laravel/fortify` is installed and
   configured (`config('fortify.features')` exists, which Fortify's service
   provider guarantees) and `flutter-bridge.fortify.enabled` is not `false`,
   the bridge resolves Fortify's bound contracts and callbacks:
   * sign in: the bridge's explicit `auth.authenticate_using` override when set,
     else `Fortify::$authenticateUsingCallback`, else the built-in check on
     `fortify.username` + password hash. `fortify.lowercase_usernames`
     lower-cases the username first (Fortify's `CanonicalizeUsername`), also
     before a custom authenticator runs;
   * sign up: the bridge's explicit `auth.register_using` override when set,
     else `Laravel\Fortify\Contracts\CreatesNewUsers`; then `Registered` is
     fired (so the app's listeners, e.g. the verification link, run as for a
     web sign-up). `fortify.lowercase_usernames` lower-cases `fortify.email`;
   * reset: `ResetsUserPasswords` through the `fortify.passwords` broker, then
     Fortify's `CompletePasswordReset` (new remember token, `PasswordReset`);
   * profile: `UpdatesUserProfileInformation`; password: `UpdatesUserPasswords`
     (then the broker's pending reset token is deleted and
     `PasswordUpdatedViaController` is fired, as Fortify's controller does);
   * password confirmation: Fortify's `ConfirmPassword` action (so
     `Fortify::confirmPasswordsUsing()` applies);
   * two-factor: `TwoFactorAuthenticationProvider`, `EnableTwoFactorAuthentication`,
     `ConfirmTwoFactorAuthentication`, `DisableTwoFactorAuthentication`,
     `GenerateNewRecoveryCodes` (and their events); the user's
     `twoFactorQrCodeSvg()` / `twoFactorQrCodeUrl()` / `recoveryCodes()` /
     `replaceRecoveryCode()` (`TwoFactorAuthenticatable`);
     `Features::optionEnabled(...)` for `confirm` and `confirmPassword`;
   * sign-in challenge events: `TwoFactorAuthenticationChallenged`,
     `TwoFactorAuthenticationFailed`, `ValidTwoFactorAuthenticationCodeProvided`,
     `RecoveryCodeReplaced`;
   * verification: `MustVerifyEmail`, `markEmailAsVerified()`, `Verified` event;
   * passkeys: `laravel/passkeys` (`passkeys()` relation, `DeletePasskey`,
     `PasskeyDeleted`).

   Fortify's actions validate with rules such as `current_password:web`; the
   bridge sets the bearer-token user on Fortify's guard (`fortify.guard`) for
   the request so those rules see the right user.
2. **Features mirror the server.** An endpoint for a Fortify feature that is
   disabled in `config/fortify.php` answers 404 `feature_disabled`; so does
   every endpoint below when Fortify is not installed/configured or
   `flutter-bridge.fortify.enabled` is `false`. This includes
   `POST auth/register` when Fortify is active and `Features::registration()`
   is off. The handshake and `GET config` publish `features.fortify` (§3) so
   the app only shows what works.
3. **Codes, not links, on phones.** Reset and verification links in emails
   open the website; the app instead asks for a **code** emailed by the bridge
   (`flutter-bridge.fortify.codes`). Codes: `random_int`, stored only as an
   HMAC (app key) in the cache, 6 digits, 10 min TTL, 5 wrong attempts then
   `code_locked` (even the right code; request a new one), single use,
   constant-time compare, rate limited. Requesting a new code replaces the
   previous one. The web flows (links) keep working unchanged; the reset
   endpoint also accepts the broker `token` from Fortify's emailed link.
4. **Sensitive actions need a recent password confirmation**, mirroring
   Fortify's `password.confirm` route middleware:
   * every two-factor management endpoint when the twoFactorAuthentication
     `confirmPassword` option is on;
   * passkey removal when the passkeys `confirmPassword` option is on (Fortify's
     default for passkeys is **on**).

   `POST auth/user/confirm-password` marks the **current access token** as
   confirmed for `auth.password_timeout` seconds (default 10800); other
   tokens of the same user are not confirmed. Otherwise 423
   `password_confirmation_required`.
5. Every new route: rate limited (named limiters, §6), JSON envelope, localized
   messages (`__()`), no user enumeration (forgot-password answers 200 with
   the same body and message for any address, and takes the same minimum
   time, `fortify.forgot_password_min_duration`, default 750 ms; queue the
   reset mail if delivery can take longer).

## 2. Endpoints (prefix `api/flutter`, `X-Flutter-App-Key` as usual)

Route names (handshake `endpoints` keys) are given in brackets.

### Guest

| Method & path | Body | Success `data` | Errors |
|---|---|---|---|
| `POST auth/login` (existing) [`auth.login`] | `email` (or the `fortify.username` field), `password`, `device_name`, `code?`, `recovery_code?` | user + authorization | 422 `validation_failed` (wrong credentials, on the username field), `two_factor_required` (`data.two_factor=true`), `two_factor_invalid`; 429 |
| `POST auth/register` (existing, now via `CreatesNewUsers`) [`auth.register`] | the app's fields (`name`/`first_name`…, `email`, `password`, `password_confirmation`, `terms`, extras) | 201 user + authorization + `email_verification_required` (bool) | 422 validation; 404 `feature_disabled` |
| `POST auth/forgot-password` [`auth.password.forgot`] | `email` | `{sent: true, channel: "code"\|"link", expires_in}` | 404, 429 |
| `POST auth/reset-password/verify-code` [`auth.password.verify_code`] | `email`, `code` | `{reset_token, expires_in}` (64 chars, `fortify.reset_token_ttl`, default 900 s, one use, bound to the address) | 422 `code_invalid`, `code_expired`; 423 `code_locked`; 429 |
| `POST auth/reset-password` [`auth.password.reset`] | `email`, `password`, `password_confirmation`, and `reset_token` **or** `token` (Fortify broker token from the email link) | `{reset: true}`; all the user's bridge tokens, personal access tokens and connections revoked | 422 `validation_failed` (the action's password rules; the `reset_token` stays usable), `reset_token_invalid`; 429 |

`email` is the `fortify.email` field. Wrong or unknown addresses get
`code_invalid` on verify-code, like a wrong code.

### Authenticated (`Authorization: Bearer …`)

| Method & path | Body | Success `data` | Errors |
|---|---|---|---|
| `GET auth/me` (existing) | | `{user}` (now with the fields in §4) | |
| `POST auth/email/verification-notification` [`auth.email.send`] | | `{sent: true, expires_in}` (emails a code) | 409 `already_verified`, 429 |
| `POST auth/email/verify` [`auth.email.verify`] | `code` | `{user}` | 422 `code_invalid`/`code_expired`, 423 `code_locked`, 409 `already_verified` |
| `PUT auth/user/profile-information` [`auth.user.profile`] | fields of `UpdatesUserProfileInformation` | `{user, email_verification_required}`; when the email changed and the user must verify, a code is emailed | 422 |
| `PUT auth/user/password` [`auth.user.password`] | `current_password`, `password`, `password_confirmation`, `revoke_other_sessions?` | `{updated: true, revoked_sessions}` | 422 |
| `POST auth/user/confirm-password` [`auth.user.confirm_password`] | `password` | `{confirmed: true, expires_in}` | 422 `password_invalid` |
| `GET auth/user/confirmed-password-status` [`auth.user.confirmed_password_status`] | | `{confirmed: bool, expires_in}` (seconds left, 0 when not confirmed) | |
| `POST auth/user/two-factor-authentication` [`auth.two_factor.enable`] | `force?` (new secret) | `{enabled: true, confirmed: false, requires_confirmation}` | 423 |
| `GET auth/user/two-factor-qr-code` [`auth.two_factor.qr_code`] | | `{svg, url}` (`otpauth://…`) | 404 `two_factor_not_enabled`, 423 |
| `GET auth/user/two-factor-secret-key` [`auth.two_factor.secret_key`] | | `{secret_key}` | 404 `two_factor_not_enabled`, 423 |
| `POST auth/user/confirmed-two-factor-authentication` [`auth.two_factor.confirm`] | `code` | `{confirmed: true, enabled, requires_confirmation, user}` | 422 `two_factor_invalid`, 423 |
| `GET auth/user/two-factor-recovery-codes` [`auth.two_factor.recovery_codes`] | | `{recovery_codes: [...]}` | 404 `two_factor_not_enabled`, 423 |
| `POST auth/user/two-factor-recovery-codes` [`auth.two_factor.regenerate_recovery_codes`] | | `{recovery_codes: [...]}` (new set) | 404 `two_factor_not_enabled`, 423 |
| `DELETE auth/user/two-factor-authentication` [`auth.two_factor.disable`] | | `{enabled: false, confirmed: false, requires_confirmation}` | 423 |
| `GET auth/user/passkeys` [`auth.passkeys.index`] | | `[{id, name, authenticator, created_at, last_used_at}]` | 404 `feature_disabled` |
| `PATCH auth/user/passkeys/{id}` [`auth.passkeys.update`] | `name` | the passkey | 404 `not_found`, 422 |
| `DELETE auth/user/passkeys/{id}` [`auth.passkeys.destroy`] | | `{deleted: true}` | 404 `not_found`, 423 |

All "423" above are `password_confirmation_required` (§1.4). Every endpoint in
this table except `auth/me` also answers 404 `feature_disabled` as in §1.2
(`confirm-password` and `confirmed-password-status` belong to no Fortify
feature: they only need the integration to be active).

`revoke_other_sessions: true` revokes every other bridge refresh/access token
pair, every other personal access token and every other connection (the one
in `X-Flutter-Connection-Id` is kept); `revoked_sessions` counts the tokens.

Passkey **creation and sign-in** need the platform credential APIs plus a
verified HTTPS domain (Digital Asset Links / Associated Domains); until the
app runs against one, passkeys are created on the website and managed here.

### Error codes added by 2.2

| HTTP | `meta.error_code` | When |
|---|---|---|
| 404 | `feature_disabled` | Fortify feature off / Fortify not active (`errors.feature` names it). The bridge's own `features.*` toggles keep answering 403 `feature_disabled` |
| 404 | `two_factor_not_enabled` | no two-factor secret yet |
| 409 | `already_verified` | email already verified |
| 422 | `code_invalid` / `code_expired` | wrong / expired emailed code (`errors.code`) |
| 423 | `code_locked` | too many wrong codes; request a new one |
| 422 | `reset_token_invalid` | unknown, used, expired or foreign `reset_token`, or a broker `token` the broker rejects |
| 422 | `password_invalid` | wrong password on confirm-password (`errors.password`) |
| 422 | `two_factor_invalid` | wrong code when confirming two-factor setup (`errors.code`) |
| 423 | `password_confirmation_required` | sensitive action without a recent confirmation of this token |

## 3. Feature discovery

`GET handshake` (`data.features.fortify`) and `GET config`
(`data.features.fortify`, merged into the remote config) add:

```json
"features": {
  "fortify": {
    "installed": true, "enabled": true,
    "registration": true, "reset_passwords": true, "email_verification": true,
    "update_profile_information": true, "update_passwords": true,
    "two_factor_authentication": true, "passkeys": true,
    "two_factor_confirm": true, "two_factor_confirm_password": true,
    "passkeys_confirm_password": true,
    "username": "email", "lowercase_usernames": true,
    "password_min": 12, "password_timeout": 10800,
    "reset_channel": "code",
    "codes": {"length": 6, "ttl": 600}
  }
}
```

`installed` = the package is present; `enabled` = the integration is active
(§1.1); every feature is `false` when it is not. `passkeys` also needs
`laravel/passkeys`. `password_min` is read from `Password::defaults()` (or
`flutter-bridge.fortify.password_min`), `null` when unknown.

## 4. User payload additions

`email_verified` (bool), `email_verified_at` (ISO 8601 or null),
`two_factor_enabled` (bool: a secret, confirmed when Fortify's `confirm`
option is on), `two_factor_pending_confirmation` (bool), `passkeys_count`
when the passkeys feature is on and the model has `passkeys()`.

They are merged into the default payload (the serialized model). The app's
`user_presenter` still decides the shape: with a custom presenter they are
added only when `flutter-bridge.fortify.user_state` is `true` (the
presenter's own keys win); a presenter can also merge them itself:

```php
use Atannex\LaravelFlutterBridge\Fortify\FortifyUserState;

return array_merge(FortifyUserState::for($user), ['id' => $user->id, /* … */]);
```

## 5. Dart API (flutter_laravel_bridge 2.2)

On `AuthManager` (and passed through by `LaravelConnectionManager`):

```dart
// discovery
FortifyFeatures get fortify;                      // from handshake/config
// guest
Future<TUser> login({...existing, String? code, String? recoveryCode});
Future<TUser> register({...existing});
Future<PasswordResetRequest> forgotPassword(String email);
Future<String> verifyPasswordResetCode({required String email, required String code}); // reset token
Future<void> resetPassword({required String email, required String password,
    required String passwordConfirmation, String? resetToken, String? token});
// signed in
Future<void> sendEmailVerification();
Future<TUser> verifyEmail(String code);
Future<TUser> updateProfile(Map<String, dynamic> fields);
Future<void> updatePassword({required String currentPassword, required String password,
    required String passwordConfirmation, bool revokeOtherSessions = false});
Future<PasswordConfirmation> confirmPassword(String password);
Future<PasswordConfirmation> confirmedPasswordStatus();
TwoFactorManager get twoFactor; // enable(), qrCode() -> TwoFactorQrCode{svg,url},
                                // secretKey(), confirm(code), recoveryCodes(),
                                // regenerateRecoveryCodes(), disable()
PasskeyManager get passkeys;    // list(), rename(id, name), delete(id)
```

New typed exceptions (all extend the existing hierarchy, carry `errorCode`):
`LaravelPasswordConfirmationRequiredException` (423),
`LaravelCodeException` (`invalid` / `expired` / `locked`),
`LaravelFeatureDisabledException` (404 `feature_disabled`),
`LaravelAlreadyVerifiedException` (409). The user model exposes
`emailVerified`, `twoFactorEnabled` through the app's parser; `AuthState`
gains nothing new (verification is a property of the user).

Note for the client: `features` in the handshake now holds one object
(`fortify`) next to the boolean flags; parse the booleans by key.

## 6. Server configuration

```php
// config/flutter-bridge.php
'fortify' => [
    'enabled' => env('FLUTTER_FORTIFY', 'auto'),   // false: no Fortify endpoints, 2.1 behaviour
    'reset_channel' => 'code',                     // or 'link': Fortify's emailed web link
    'codes' => ['length' => 6, 'ttl' => 600, 'max_attempts' => 5, 'cache_store' => null],
    'reset_token_ttl' => 900,
    'verification' => [
        'send_code_on_register' => true,           // auth/register emails a code
        'send_code_on_email_change' => true,       // profile email change emails a code
    ],
    'notifications' => [                          // classes receiving (string $code, int $expiresIn)
        'reset_password' => ResetPasswordCodeNotification::class,
        'verify_email' => VerifyEmailCodeNotification::class,
    ],
    'user_state' => 'auto',                        // §4
    'password_min' => null,
],
```

The code mails are markdown mail notifications (subject, greeting and lines
through `__()`, so `lang/<locale>.json` translates them; `:app` is
`config('app.name')`), sent synchronously so the code arrives while the user
waits. Replace them with your own subclasses of
`Atannex\LaravelFlutterBridge\Notifications\Fortify\VerificationCodeNotification`
(or any notification taking `$code, $expiresIn`).

Registration still fires `Registered`, so an app that sends Laravel's
verification link from that event sends both the link and the code.

### Rate limiters (`flutter-bridge.rate_limits`, per minute; 0/null disables)

| Limiter | Routes | Default | Keyed by |
|---|---|---|---|
| `flutter-password-reset` | `auth.password.forgot` | 3 + 10 | lowercase email + IP, and IP |
| `flutter-codes` | `auth.password.verify_code`, `auth.password.reset`, `auth.email.verify` | 10 | user (or lowercase email) + IP |
| `flutter-verification` | `auth.email.send` | 6 | user |
| `flutter-confirm-password` | `auth.user.confirm_password` | 6 | user |
| `flutter-account` | profile, password, two-factor and passkey management | 30 | user |

The signed-in routes also keep `flutter-api`. `auth/login` and
`auth/register` keep `flutter-auth` (now keyed by the `fortify.username`
field).
