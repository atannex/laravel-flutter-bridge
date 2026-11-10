# Laravel Flutter Bridge (Backend Package)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/atannex/laravel-flutter-bridge.svg?style=flat-square)](https://packagist.org/packages/atannex/laravel-flutter-bridge)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

An enterprise-grade Laravel package designed to seamlessly connect Laravel backends to Flutter applications. Provides standardized API response envelopes, device FCM registration, Sanctum token management, WebSocket broadcasting auth, and automated Dart model/route code generation.

---

## 🌟 Key Features

- **Standardized API Envelope**: Unified JSON envelope across all endpoints (`success`, `message`, `data`, `errors`, `meta`).
- **Sanctum Device Auth**: Issue and revoke personal access tokens per physical device with auto-refresh support.
- **Push Notification Registry**: Multi-device management tracking FCM tokens, OS version, platform, and active status.
- **Bearer Broadcast Auth**: Dedicated WebSocket authentication route for Laravel Reverb and Pusher without session cookies.
- **Dart Code Generator**: Artisan commands to reflect Eloquent models into null-safe Dart classes and export typed API route registries.
- **Middleware Suite**: Enforces JSON expectations, client telemetry logging, and response standardization.
- **Connection Management**: Handshake discovery, heartbeated connection sessions, remote sign-out, server → client commands, app keys with version policy and rotating refresh tokens.

---

## 📦 Installation

Add the package to your Laravel application:

```bash
composer require atannex/laravel-flutter-bridge
```

Publish configuration and migrations:

```bash
php artisan flutter:install
php artisan migrate
```

Verify your environment status:

```bash
php artisan flutter:status
```

---

## 🚀 Quick Setup

### 1. Update User Model
Add Sanctum's `HasApiTokens` plus the `HasFlutterTokens` and `HasFlutterDevices` traits to your `User` model
(`HasApiTokens` alone is enough for the bridge endpoints; the bridge traits add helpers such as
`createFlutterToken()` and `flutterConnections()`):

```php
use Atannex\LaravelFlutterBridge\Traits\HasFlutterDevices;
use Atannex\LaravelFlutterBridge\Traits\HasFlutterTokens;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFlutterTokens, HasFlutterDevices;
}
```

### 2. Using Response Macros
In your controllers, take advantage of built-in Flutter response macros:

```php
use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        $posts = Post::latest()->paginate(15);
        return response()->flutterPaginated($posts, 'Posts fetched');
    }

    public function show(Post $post)
    {
        return response()->flutterSuccess($post);
    }

    public function destroy(Post $post)
    {
        $post->delete();
        return response()->flutterSuccess(null, 'Post deleted');
    }
}
```

---

## 🔌 Connection Management (Bridge v2)

Bridge protocol **2.0.0** adds live *connection sessions*, registered *client apps* with a version
policy, server → client *commands*, and real refresh-token rotation. The wire contract is documented in
[`docs/CONNECTIONS.md`](docs/CONNECTIONS.md).

### Setup

```bash
php artisan flutter:install      # publishes config + migrations
php artisan migrate              # flutter_apps, flutter_connections, flutter_connection_commands, flutter_refresh_tokens
php artisan flutter:app:create "Acme Mobile" --platforms=android,ios --min-version=1.2.0 --update-url=https://example.com/app
php artisan flutter:export-config --base-url=https://api.example.com --app-key=fba_xxx
```

`flutter:export-config` writes `laravel_bridge_config.g.dart` containing a ready-to-use
`const laravelBridgeConfig = LaravelConnection(...)` for the Flutter client.

Key options in `config/flutter-bridge.php`:

| Key | Default | Purpose |
| :--- | :--- | :--- |
| `connections.heartbeat_interval` / `timeout` | `30` / `90` s | Heartbeat cadence; a session is online while seen within `timeout`. |
| `connections.require_app_key` | `false` | Reject requests without `X-Flutter-App-Key` (403 `app_key_required`). |
| `connections.min_app_version` | `null` | Global minimum client version (426 `upgrade_required`); per app via `flutter_apps.min_version`. |
| `connections.command_ttl` | `1440` min | How long a queued command stays deliverable. |
| `connections.prune_after_days` | `30` | Retention used by `flutter:connections:prune`. |
| `features.*` | `true` | Toggle registration / social auth / 2FA (403 `feature_disabled`). |
| `remote_config` | `[]` | Served by the handshake and `GET config`, merged with the app's `settings`. |
| `rate_limits.*` | see below | Per-minute limits for the named limiters; `0`/`null` disables one. |
| `two_factor.max_attempts` | `5` | Wrong 2FA codes before the challenge is destroyed (400 `two_factor_locked`). |
| `notifications.fcm_credentials` | `null` | Firebase service-account JSON (path, raw JSON or base64) for push. |
| `broadcasting.expose` / `broadcasting.client` | `null` | Broadcasting connection and public WS host/port/scheme advertised to clients (`reverb` or `pusher`). |

All bridge routes run through `flutter.headers`, `flutter.response`, `flutter.app`, `flutter.version` and
`flutter.connection`. Errors carry a machine-readable `meta.error_code` (`unauthenticated`,
`invalid_refresh_token`, `refresh_token_reused`, `connection_revoked`, `upgrade_required`,
`validation_failed`, `too_many_requests`, `maintenance`, …).

Social sign-in verifies the provider token through `auth.social_user_resolver` (an invokable returning
`['email' => ..., 'name' => ..., 'id' => ...]`) or Laravel Socialite when installed; listen to
`FlutterTwoFactorCodeIssued` to deliver 2FA codes.

### Rate limiting

Every bridge route carries a named limiter (`throttle:<name>`, registered by the service provider).
Throttled requests answer 429 with `meta.error_code = too_many_requests` and a `Retry-After` header.

| Limiter | Routes | Default / min | Env |
| :--- | :--- | :--- | :--- |
| `flutter-auth` | login, register, social, 2FA send-otp | 5 per email+IP, 30 per IP | `FLUTTER_RATE_LIMIT_AUTH`, `FLUTTER_RATE_LIMIT_AUTH_IP` |
| `flutter-2fa` | 2FA verify | 10 per IP | `FLUTTER_RATE_LIMIT_2FA` |
| `flutter-refresh` | token refresh | 20 per IP | `FLUTTER_RATE_LIMIT_REFRESH` |
| `flutter-connect` | `connections/connect` | 10 per device_id+IP | `FLUTTER_RATE_LIMIT_CONNECT` |
| `flutter-heartbeat` | heartbeat / disconnect | 12 per connection | `FLUTTER_RATE_LIMIT_HEARTBEAT` |
| `flutter-api` | everything else | 120 per user (or IP) | `FLUTTER_RATE_LIMIT_API` |

2FA codes are also brute-force capped: after `two_factor.max_attempts` (`FLUTTER_2FA_MAX_ATTEMPTS`, default 5)
wrong codes the challenge is destroyed and the client must request a new code.

### Push notifications (FCM HTTP v1)

```ini
FCM_CREDENTIALS=/path/to/service-account.json   # or the raw JSON / base64 JSON
FCM_PROJECT_ID=my-firebase-project               # optional, defaults to the credentials' project_id
```

Create the key in the Firebase console (**Project settings → Service accounts → Generate new private key**).
The `flutter_push` notification channel mints OAuth2 access tokens from the service account (RS256 JWT,
cached until shortly before expiry, refreshed and retried once on 401), sends to all of a user's device
tokens concurrently (`FCM_CONCURRENCY`, default 10), clears tokens FCM reports as `UNREGISTERED`/invalid
(`FlutterPushTokenInvalidated` event) and fires `FlutterPushSent` with sent / failed / invalidated counts.
`FCM_BEARER_TOKEN` (+ `FCM_PROJECT_ID`) remains as a static-token fallback for testing; with nothing
configured, pushes are only logged. `php artisan flutter:status` validates the credentials offline. See
[`docs/NOTIFICATIONS_CHANNEL.md`](docs/NOTIFICATIONS_CHANNEL.md).

### Artisan commands

| Command | Description |
| :--- | :--- |
| `flutter:apps` | List client apps. |
| `flutter:app:create {name} --platforms= --min-version= --latest-version= --update-url=` | Register an app and print its key. |
| `flutter:app:update {key} --status= --min-version= --latest-version= --update-url= --name=` | Update status / version policy. |
| `flutter:app:delete {key} --force` | Delete an app and revoke its sessions. |
| `flutter:connections --user= --status= --app=` | List sessions with summary statistics. |
| `flutter:connections:revoke {id?} --user= --reason=` | Revoke a session or all sessions of a user. |
| `flutter:connections:send {type} {id?} --user= --payload='{"title":"Hi"}' --all` | Queue a command (`revoked`, `logout`, `refresh_config`, `force_update`, `message`, …). |
| `flutter:connections:prune --days=` | Delete ended sessions, old commands and dead refresh tokens. |
| `flutter:export-config --base-url= --app-key= --output=` | Generate the Dart connection config. |

### Facade usage

```php
use Atannex\LaravelFlutterBridge\Facades\FlutterBridge;

// Commands are delivered on the next heartbeat and pushed instantly as
// "BridgeCommand" on private-flutter.connections.{id} when broadcasting is enabled.
FlutterBridge::connections()->sendCommandToUser($user->id, 'message', ['title' => 'Hi', 'body' => 'Welcome back']);
FlutterBridge::connections()->sendCommand($connectionId, 'force_update', ['min_version' => '2.0.0']);

FlutterBridge::connections()->revoke($connectionId, 'lost_device');   // revokes its tokens too
FlutterBridge::connections()->revokeAllForUser($user->id, exceptId: $currentId);
FlutterBridge::connections()->isUserOnline($user->id);
FlutterBridge::connections()->stats();

$app = FlutterBridge::apps()->create('Acme Mobile', ['min_version' => '1.2.0']);
FlutterBridge::apps()->suspend($app);

$pair = FlutterBridge::tokens()->issue($user, 'Pixel 9', ['*'], null, $connectionId, $deviceId);
```

Events: `FlutterConnectionEstablished`, `FlutterConnectionDisconnected`, `FlutterConnectionRevoked`
(broadcast as `ConnectionRevoked`) and `FlutterCommandQueued` (broadcast as `BridgeCommand`). Broadcasting
only happens when `flutter-bridge.broadcasting.enabled` is true and the default driver is not `null`/`log`.
The provider authorizes the `flutter.connections.{connection}` and `users.{id}` private channels.

### Scheduling cleanup

```php
// routes/console.php (Laravel 11+)
use Illuminate\Support\Facades\Schedule;

Schedule::command('flutter:connections:prune')->daily();
```

On Laravel 10 add `$schedule->command('flutter:connections:prune')->daily();` to `App\Console\Kernel::schedule()`.

---

## 🛠️ Artisan Commands

### Generate Dart Model from Eloquent
```bash
php artisan flutter:generate-model User
```
Generates a null-safe Dart model with `fromJson()`, `toJson()`, and constructor.

### Export Routes to Dart
```bash
php artisan flutter:export-routes --prefix=api
```
Generates a Dart route file with all endpoints typed as static constants.

---

## 🧪 Testing

```bash
composer test
```
