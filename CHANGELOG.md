# Changelog

All notable changes to `atannex/laravel-flutter-bridge` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The wire protocol shared with the Flutter package is
specified in [`docs/CONNECTIONS.md`](docs/CONNECTIONS.md).

## [2.1.0] - 2026-11-10

Plug your own sign-in into the bridge. Bridge wire protocol stays `2.0.0`; the new error codes are
additive.

### Added

- `auth.authenticate_using`: custom sign-in for `auth/login` (invokable class or callable receiving the
  request, returning the user or null). Falls back to Laravel Fortify's `Fortify::authenticateUsing()`
  callback when Fortify is installed, then to the built-in email/password check.
- `auth.register_using`: custom sign-up for `auth/register` (invokable class, class with
  `create(array $input)`, or callable receiving `(array $input, Request $request)`). Falls back to
  Fortify's bound `CreatesNewUsers` action, then the built-in sign-up. Every request field is passed,
  so app-specific fields reach the action; its validation errors return `422 validation_failed`.
- Authenticator-app two-step sign-in on `auth/login` and `auth/social` via `auth.two_factor_challenge`
  (`auto` default, `true`, `false`). With `auto`, users with a Fortify two-factor secret (confirmed,
  when Fortify's `confirm` option is on) must send `code` or `recovery_code`. Codes are verified with
  Fortify's `TwoFactorAuthenticationProvider`; recovery codes work once and are replaced. New error
  codes `422 two_factor_required` and `422 two_factor_invalid` with `data: {"two_factor": true}`.
- `auth.user_presenter`: shapes `user` in every auth response and `auth/me`.
- `FlutterAuthenticator` service and an optional `$data` argument on `FlutterResponse::error()`.
- `laravel/fortify` listed under `suggest`.

### Changed

- `auth/login` and `auth/social` accept optional `code` and `recovery_code` fields.
- With a custom or Fortify sign-in, `auth/login` no longer requires `email` itself (your authenticator
  reads its own fields); `password` is still required.
- `auth/register` now fires `Illuminate\Auth\Events\Registered` (also for the built-in sign-up), so
  listeners such as email verification run for mobile sign-ups.
- Apps with Laravel Fortify and users who enabled an authenticator app: `auth/login` now asks those users
  for their code. Set `auth.two_factor_challenge` to `false` to keep the 2.0 behaviour.

## [2.0.0] - 2026-11-09

Bridge protocol v2: connection management between Laravel and Flutter. Pairs with
`flutter_laravel_bridge` 2.0.0.

### Added

- **Handshake & discovery**: `GET handshake` (server, bridge, app, features, endpoints map,
  broadcasting config, heartbeat settings, remote config), `GET ping`, `GET config`.
- **Connection sessions** (`flutter_connections`): connect / resume, heartbeat, disconnect; users can
  list their sessions and revoke one or all others. Revoking a session revokes its tokens.
- **Server → client commands** (`flutter_connection_commands`): `revoked`, `logout`,
  `refresh_config`, `force_update`, `message` and custom types, delivered on heartbeat and broadcast
  instantly as `BridgeCommand` when broadcasting is enabled.
- **Client apps** (`flutter_apps`): app keys, suspend/activate, per-app minimum/latest version and
  remote settings. Outdated clients receive `426 upgrade_required` with the update URL.
- **Machine-readable error codes** in `meta.error_code` on every error envelope.
- **Rate limiting** on every bridge route (auth, 2FA, refresh, connect, heartbeat, API), configurable
  per limiter via `.env`; `429 too_many_requests` with `Retry-After`.
- **2FA attempt cap**: the challenge is destroyed after `two_factor.max_attempts` wrong codes
  (`two_factor_locked`).
- **FCM HTTP v1 with service accounts** (`FCM_CREDENTIALS`): OAuth access tokens are minted and
  cached automatically, retried once on 401, sent concurrently; uninstalled-app tokens are cleared.
  New events `FlutterPushSent` and `FlutterPushTokenInvalidated`. Sound, badge and Android channel
  are now included in the payload.
- Events: `FlutterConnectionEstablished`, `FlutterConnectionDisconnected`, `FlutterConnectionRevoked`,
  `FlutterCommandQueued`, `FlutterTwoFactorCodeIssued`.
- Facade: `FlutterBridge::connections()`, `::apps()`, `::tokens()`.
- Artisan: `flutter:apps`, `flutter:app:create`, `flutter:app:update`, `flutter:app:delete`,
  `flutter:connections`, `flutter:connections:revoke`, `flutter:connections:send`,
  `flutter:connections:prune`, `flutter:export-config` (generates the Dart connection profile).
  `flutter:status` now reports connections, apps and FCM configuration.
- Response headers `X-Laravel-Flutter-Bridge` and `X-Flutter-Server-Time`.
- PHPStan (Larastan) level 5 in CI.

### Changed

- **Supported Laravel versions are now 12 and 13** (PHP 8.2+, 8.3+ for Laravel 13). Laravel 10 and
  11 are past their security support and every release carries open security advisories.
- **Refresh tokens are real**: stored hashed in `flutter_refresh_tokens`, rotated on every refresh,
  bound to the device and connection session; replaying a rotated token revokes the whole family
  (`refresh_token_reused`). `POST auth/refresh` no longer requires a valid access token.
- Bridge middleware (`flutter.headers`, `flutter.response`, `flutter.app`, `flutter.version`,
  `flutter.connection`) is applied to every bridge route.
- Broadcasting auth responses are enveloped as `data: {auth, channel_data}` and require `socket_id`.
- The device route parameter is now `{device}`.
- `FlutterPushChannel::send()` returns a `['sent', 'failed', 'invalidated']` summary.
- FCM credentials are read from the `flutter-bridge.notifications` config (works with
  `config:cache`).
- `FlutterPresenceManager` delegates to the connection manager.

### Fixed

- The `flutter_push` notification channel failed to resolve on Laravel 13, which binds
  `extend()` callbacks to the channel manager.
- Social sign-in trusted the email sent by the client, allowing account takeover. Provider tokens are
  now verified (Socialite or `auth.social_user_resolver`); unverified mode is opt-in.
- Device registration ignored the bearer user, and anonymous callers could delete devices owned by a
  user.
- 2FA codes were generated but never delivered (now dispatched via `FlutterTwoFactorCodeIssued`) and
  used a non-cryptographic random generator.
- Empty push `data` was sent as a JSON array, which FCM rejects.

### Upgrade notes

- Run `php artisan migrate` (four new tables).
- Users must sign in once more: 1.x refresh tokens were never stored server-side.
- Use a shared cache store (Redis, database or file) in production — rate limits and 2FA challenges
  live in the cache.
- Schedule `php artisan flutter:connections:prune` (e.g. daily).

## [1.0.0]

### Added

- Initial release: Sanctum device tokens, standardized JSON envelope, Bearer broadcast
  authorization for Reverb/Pusher, FCM device registration and `flutter_push` notification channel,
  social and 2FA endpoints, Dart model/route/translation generators.

[2.1.0]: https://github.com/atannex/laravel-flutter-bridge/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/atannex/laravel-flutter-bridge/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/atannex/laravel-flutter-bridge/releases/tag/v1.0.0
