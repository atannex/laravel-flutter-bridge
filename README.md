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

### Push notifications (FCM HTTP v1)

```ini
FCM_CREDENTIALS=/path/to/service-account.json   # or the raw JSON / base64 JSON
FCM_PROJECT_ID=my-firebase-project               # optional, defaults to the credentials' project_id
```
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
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('flutter:connections:prune')->daily();
```


---

## 🔐 Use your own sign-in (Fortify)

By default `auth/login` checks email + password and `auth/register` creates a user from
`name` / `email` / `password`. If your app has its own sign-in rules, plug them in so the mobile
endpoints cannot bypass them. Every option is optional and takes an invokable class name (works with
`php artisan config:cache`) or a callable.

```php
// config/flutter-bridge.php
'auth' => [
    // ...

    // (Request $request): ?Authenticatable  — null = wrong credentials (422)
    'authenticate_using'   => App\Actions\Fortify\AuthenticateUser::class,

    // (array $input, Request $request): Authenticatable  — gets every request field
    'register_using'       => App\Actions\Fortify\CreateNewUser::class,

    // (Authenticatable $user): array  — shapes `user` in auth responses and auth/me
    'user_presenter'       => App\Http\Presenters\UserPresenter::class,

    // 'auto' | true | false — authenticator-app two-step sign-in
    'two_factor_challenge' => 'auto',
],
```

When **Laravel Fortify** is installed you usually need none of the above:

| Step | Order used |
| :--- | :--- |
| Sign-in (`auth/login`) | `authenticate_using` → `Fortify::authenticateUsing()` callback → built-in email/password |
| Sign-up (`auth/register`) | `register_using` → Fortify's bound `CreatesNewUsers` action (e.g. `App\Actions\Fortify\CreateNewUser`, with your password rules, roles and extra fields) → built-in |
| Two-step sign-in | users with a Fortify two-factor secret (confirmed, when Fortify's `confirm` option is on) must send `code` or `recovery_code` |
| `user` in responses | `user_presenter` → the model |

A class registered as `register_using` may be invokable or expose Fortify's `create(array $input)`.
Sign-up fires `Illuminate\Auth\Events\Registered`, and validation errors from your action come back as
the usual `422 validation_failed` envelope.

Two-step flow for accounts with an authenticator app:

```text
POST auth/login {email, password}                 → 422 meta.error_code "two_factor_required", data {"two_factor": true}
POST auth/login {email, password, code: "123456"} → 200 tokens   (or recovery_code: "xxxxxxxxxx-xxxxxxxxxx", usable once)
wrong code                                        → 422 "two_factor_invalid"
```

`auth/social` applies the same check after the provider token is verified. The email one-time-code
endpoints (`auth/2fa/send-otp`, `auth/2fa/verify`) are a separate feature; set
`features.two_factor` to `false` if your app should only allow authenticator-app sign-in.

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
