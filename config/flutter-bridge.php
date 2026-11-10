<?php

declare(strict_types=1);
use Atannex\LaravelFlutterBridge\Notifications\Fortify\ResetPasswordCodeNotification;
use Atannex\LaravelFlutterBridge\Notifications\Fortify\VerifyEmailCodeNotification;

return [
    /*
    |--------------------------------------------------------------------------
    | API Route Prefix & Middleware
    |--------------------------------------------------------------------------
    |
    | Defines the URL prefix and middleware stack applied to all bridge routes.
    | By default, routes will be available under /api/flutter.
    |
    */
    'prefix' => env('FLUTTER_BRIDGE_PREFIX', 'api/flutter'),

    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Sanctum token issuing, refresh tokens, and authentication
    | callbacks. Supports device-specific personal access tokens.
    |
    */
    'auth' => [
        'enabled' => true,
        'guard' => 'sanctum',
        'user_model' => env('AUTH_MODEL', 'App\\Models\\User'),
        'token_name_prefix' => 'flutter-app',
        'token_abilities' => ['*'],

        // Expiration in minutes (null for no expiration)
        'access_token_ttl' => env('FLUTTER_ACCESS_TOKEN_TTL', 60),     // 1 hour
        'refresh_token_ttl' => env('FLUTTER_REFRESH_TOKEN_TTL', 43200), // 30 days

        // Whether to automatically revoke older tokens from the same device
        'single_session_per_device' => true,

        // Verifies social provider tokens: an invokable class name or callable
        // receiving (string $provider, string $token, Request $request) and
        // returning ['email' => ..., 'name' => ..., 'id' => ...] or null.
        // When null, Laravel Socialite is used if installed.
        'social_user_resolver' => null,

        // INSECURE: trust the email / name sent by the client without verifying
        // the provider token. Only for local development.
        'social_trust_client_profile' => env('FLUTTER_SOCIAL_TRUST_CLIENT', false),

        /*
        | Use your own sign-in (all optional). Each option takes null, an
        | invokable class name or a callable; prefer class names so
        | `php artisan config:cache` keeps working.
        */

        // Resolves the user for POST auth/login: (Request $request): ?Authenticatable.
        // Null result = wrong credentials (422). When null, Fortify's
        // Fortify::authenticateUsing() callback is used if registered, else
        // the built-in email / password check.
        'authenticate_using' => null,

        // Creates the user for POST auth/register: (array $input, Request $request): Authenticatable.
        // Receives every request field. When null, Fortify's CreatesNewUsers
        // action is used if bound, else the built-in name / email / password sign-up.
        'register_using' => null,

        // Shapes `user` in every auth response and GET auth/me:
        // (Authenticatable $user): array. When null, the model is serialized.
        'user_presenter' => null,

        // Authenticator-app (TOTP) two-step sign-in on auth/login and auth/social:
        // 'auto' = on when Fortify is installed and the user has a two-factor
        // secret (confirmed, when Fortify's "confirm" option is on); true = always
        // enforce for such users (requires Fortify); false = never.
        'two_factor_challenge' => env('FLUTTER_TWO_FACTOR_CHALLENGE', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Requests per minute for the named limiters applied to bridge routes.
    | Throttled requests answer 429 with meta.error_code "too_many_requests"
    | and a Retry-After header. Set a value to 0 or null to disable it.
    |
    |   flutter-auth      login, register, social, 2fa send-otp
    |   flutter-2fa       2fa verify
    |   flutter-refresh   token refresh
    |   flutter-connect   connections/connect
    |   flutter-heartbeat connections/{id}/heartbeat and /disconnect
    |   flutter-api       every other bridge route
    |
    | Laravel Fortify endpoints (docs/FORTIFY.md), on top of flutter-api for
    | the signed-in ones:
    |
    |   flutter-password-reset  auth/forgot-password
    |   flutter-codes           auth/reset-password/verify-code, auth/reset-password,
    |                           auth/email/verify
    |   flutter-verification    auth/email/verification-notification
    |   flutter-confirm-password auth/user/confirm-password
    |   flutter-account         profile, password, two-factor and passkey management
    |
    */
    'rate_limits' => [
        'auth' => [
            // Keyed by lowercase email + IP.
            'per_minute' => env('FLUTTER_RATE_LIMIT_AUTH', 5),
            // Keyed by IP only.
            'per_ip_per_minute' => env('FLUTTER_RATE_LIMIT_AUTH_IP', 30),
        ],
        'two_factor' => [
            // Keyed by IP.
            'per_minute' => env('FLUTTER_RATE_LIMIT_2FA', 10),
        ],
        'refresh' => [
            // Keyed by IP.
            'per_minute' => env('FLUTTER_RATE_LIMIT_REFRESH', 20),
        ],
        'connections' => [
            // Keyed by device_id + IP.
            'connect_per_minute' => env('FLUTTER_RATE_LIMIT_CONNECT', 10),
            // Keyed by connection id.
            'heartbeat_per_minute' => env('FLUTTER_RATE_LIMIT_HEARTBEAT', 12),
        ],
        'api' => [
            // Keyed by user id, or IP for guests.
            'per_minute' => env('FLUTTER_RATE_LIMIT_API', 120),
        ],
        'password_reset' => [
            // Reset emails, keyed by lowercase email + IP.
            'per_minute' => env('FLUTTER_RATE_LIMIT_PASSWORD_RESET', 3),
            // Keyed by IP only.
            'per_ip_per_minute' => env('FLUTTER_RATE_LIMIT_PASSWORD_RESET_IP', 10),
        ],
        'codes' => [
            // Code / reset submissions, keyed by lowercase email (or user) + IP.
            'per_minute' => env('FLUTTER_RATE_LIMIT_CODES', 10),
        ],
        'verification' => [
            // Verification emails, keyed by user (Fortify's "6,1").
            'per_minute' => env('FLUTTER_RATE_LIMIT_VERIFICATION', 6),
        ],
        'confirm_password' => [
            // Keyed by user.
            'per_minute' => env('FLUTTER_RATE_LIMIT_CONFIRM_PASSWORD', 6),
        ],
        'account' => [
            // Keyed by user.
            'per_minute' => env('FLUTTER_RATE_LIMIT_ACCOUNT', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | After max_attempts wrong codes the challenge is destroyed and the client
    | receives 400 "two_factor_locked"; a new code must be requested.
    |
    */
    'two_factor' => [
        'max_attempts' => (int) env('FLUTTER_2FA_MAX_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Fortify over the bridge (docs/FORTIFY.md)
    |--------------------------------------------------------------------------
    |
    | When laravel/fortify is installed and configured, the bridge exposes
    | Fortify's account features to the app with bearer tokens, through
    | Fortify's own actions, rules, events and config/fortify.php features.
    | Disabled features answer 404 "feature_disabled".
    |
    */
    'fortify' => [
        // 'auto' = on when Fortify is installed and configured; false = off.
        'enabled' => env('FLUTTER_FORTIFY', 'auto'),

        // forgot-password delivery: 'code' (emailed code, for the app) or
        // 'link' (Fortify's emailed web link; reset with its `token`).
        'reset_channel' => env('FLUTTER_FORTIFY_RESET_CHANNEL', 'code'),

        // Minimum duration of forgot-password, in microseconds: the same with
        // or without an account, so timing does not reveal registered
        // addresses. Queue the reset mail when sending can take longer.
        'forgot_password_min_duration' => (int) env('FLUTTER_FORTIFY_FORGOT_MIN_DURATION', 750_000),

        // Emailed codes (password reset, email verification).
        'codes' => [
            'length' => (int) env('FLUTTER_FORTIFY_CODE_LENGTH', 6),
            // Seconds a code stays valid.
            'ttl' => (int) env('FLUTTER_FORTIFY_CODE_TTL', 600),
            // Wrong codes before it locks (423 code_locked).
            'max_attempts' => (int) env('FLUTTER_FORTIFY_CODE_MAX_ATTEMPTS', 5),
            // Cache store holding hashed codes (null = default store).
            'cache_store' => env('FLUTTER_FORTIFY_CODE_STORE'),
        ],

        // Seconds the reset_token from reset-password/verify-code lasts (one use).
        'reset_token_ttl' => (int) env('FLUTTER_FORTIFY_RESET_TOKEN_TTL', 900),

        'verification' => [
            // Email a verification code after auth/register (MustVerifyEmail users).
            'send_code_on_register' => true,
            // Email a code after an email change on auth/user/profile-information.
            'send_code_on_email_change' => true,
        ],

        // Notification classes receiving (string $code, int $expiresIn).
        'notifications' => [
            'reset_password' => ResetPasswordCodeNotification::class,
            'verify_email' => VerifyEmailCodeNotification::class,
        ],

        // Add email_verified, two_factor_enabled, … to `user` (docs/FORTIFY.md §4):
        // 'auto' = default payload only, true = also a custom user_presenter's array.
        'user_state' => 'auto',

        // Advertised password_min; null reads Password::defaults().
        'password_min' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Device Management & Push Notifications
    |--------------------------------------------------------------------------
    |
    | Settings for storing mobile device metadata (iOS, Android, Web, macOS, Windows)
    | and synchronizing FCM / APNs push notification tokens.
    |
    */
    'devices' => [
        'enabled' => true,
        'table_name' => 'flutter_devices',
        'enforce_device_registration' => false,
        'prune_after_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Notifications (FCM HTTP v1)
    |--------------------------------------------------------------------------
    |
    | Credentials used by the `flutter_push` notification channel.
    |
    | fcm_credentials: a Google service account (Firebase console > Project
    | settings > Service accounts > Generate new private key) given as a path
    | to the JSON file, the raw JSON string or base64-encoded JSON. OAuth2
    | access tokens are minted from it and cached automatically.
    |
    | fcm_project_id: optional, defaults to the credentials' project_id.
    |
    | fcm_bearer_token: static access token used only when no credentials are
    | configured (testing; Google tokens expire after an hour).
    |
    | With neither credentials nor a bearer token, notifications are logged.
    |
    */
    'notifications' => [
        'fcm_credentials' => env('FCM_CREDENTIALS'),
        'fcm_project_id' => env('FCM_PROJECT_ID'),
        'fcm_bearer_token' => env('FCM_BEARER_TOKEN'),

        // Concurrent FCM requests per batch.
        'fcm_concurrency' => (int) env('FCM_CONCURRENCY', 10),

        // Seconds before an FCM / OAuth request times out.
        'fcm_timeout' => (int) env('FCM_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-Time Broadcasting / WebSockets
    |--------------------------------------------------------------------------
    |
    | Provides Sanctum Bearer token broadcast channel authorization for
    | Laravel Reverb, Pusher, or Soketi without requiring CSRF cookies.
    |
    */
    'broadcasting' => [
        'enabled' => env('FLUTTER_BROADCASTING_ENABLED', true),
        'auth_path' => 'broadcasting/auth',

        // Broadcasting connection (from config/broadcasting.php) advertised to
        // Flutter clients in the handshake. Null uses broadcasting.default.
        // Only "reverb" and "pusher" drivers are advertised.
        'expose' => env('FLUTTER_BROADCAST_CONNECTION'),

        // Public WebSocket endpoint overrides for clients, useful when the
        // server-side host (e.g. 127.0.0.1) differs from the public one.
        'client' => [
            'host' => env('FLUTTER_BROADCAST_HOST'),
            'port' => env('FLUTTER_BROADCAST_PORT'),
            'scheme' => env('FLUTTER_BROADCAST_SCHEME'),
        ],

        // Register the "flutter.connections.{connection}" and "users.{id}"
        // private channel authorization callbacks.
        'register_channels' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Sessions (Bridge v2)
    |--------------------------------------------------------------------------
    |
    | Live links between a device / app install and the server. Sessions are
    | kept alive by heartbeats, can be listed and revoked by users and admins,
    | and receive server -> client commands.
    |
    */
    'connections' => [
        'enabled' => env('FLUTTER_CONNECTIONS_ENABLED', true),
        'table_name' => 'flutter_connections',
        'commands_table_name' => 'flutter_connection_commands',

        // Seconds between client heartbeats.
        'heartbeat_interval' => (int) env('FLUTTER_HEARTBEAT_INTERVAL', 30),

        // Seconds without a heartbeat after which a session is considered offline.
        'timeout' => (int) env('FLUTTER_CONNECTION_TIMEOUT', 90),

        // Minimum seconds between last_seen_at writes performed by middleware.
        'touch_throttle' => 10,

        // Reject requests that do not carry a valid X-Flutter-App-Key header.
        'require_app_key' => env('FLUTTER_REQUIRE_APP_KEY', false),

        // Global minimum client version (overridden per app by flutter_apps.min_version).
        'min_app_version' => env('FLUTTER_MIN_APP_VERSION'),

        // Minutes a queued command stays deliverable (null = never expires).
        'command_ttl' => 1440,

        // Days after which ended sessions, delivered commands and dead refresh
        // tokens are deleted by flutter:connections:prune.
        'prune_after_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Apps
    |--------------------------------------------------------------------------
    */
    'apps' => [
        'table_name' => 'flutter_apps',
    ],

    /*
    |--------------------------------------------------------------------------
    | Refresh Tokens
    |--------------------------------------------------------------------------
    |
    | Refresh tokens are stored hashed (sha256) and rotated on every use.
    |
    */
    'refresh_tokens' => [
        'table_name' => 'flutter_refresh_tokens',
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Toggles
    |--------------------------------------------------------------------------
    |
    | Disabled features answer 403 with meta.error_code "feature_disabled" and
    | are reported as false in the handshake.
    |
    */
    'features' => [
        'registration' => true,
        'social_auth' => true,
        'two_factor' => true,
        'push' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Config
    |--------------------------------------------------------------------------
    |
    | Arbitrary values served to clients by the handshake and GET /config.
    | A client app's own `settings` are merged on top of these.
    |
    */
    'remote_config' => [],

    /*
    |--------------------------------------------------------------------------
    | Standardized Response Envelope
    |--------------------------------------------------------------------------
    |
    | Configures standard JSON envelope structure for enterprise consistency:
    | {
    |    "success": true,
    |    "message": "...",
    |    "data": ...,
    |    "errors": ...,
    |    "meta": { "timestamp": "...", "version": "..." }
    | }
    |
    */
    'envelope' => [
        'enabled' => true,
        'api_version' => env('FLUTTER_API_VERSION', 'v1'),
        'include_trace_id' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Flutter Code Generation Configuration
    |--------------------------------------------------------------------------
    |
    | Paths and namespaces used by artisan commands to generate Dart data models
    | and client route definitions for the Flutter application.
    |
    */
    'generator' => [
        'output_path' => env('FLUTTER_EXPORT_PATH', '../flutter-laravel-bridge/lib/src/generated'),
        'model_namespace' => 'App\\Models',
    ],
];
