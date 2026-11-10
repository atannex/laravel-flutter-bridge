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
