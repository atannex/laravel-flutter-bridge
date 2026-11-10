<?php

declare (strict_types = 1);

namespace Atannex\LaravelFlutterBridge;

use Atannex\LaravelFlutterBridge\Broadcasting\FlutterPresenceManager;
use Atannex\LaravelFlutterBridge\Console\BridgeStatusCommand;
use Atannex\LaravelFlutterBridge\Console\BroadcastTestEventCommand;
use Atannex\LaravelFlutterBridge\Console\CreateAppCommand;
use Atannex\LaravelFlutterBridge\Console\DeleteAppCommand;
use Atannex\LaravelFlutterBridge\Console\ExportConfigCommand;
use Atannex\LaravelFlutterBridge\Console\ExportRoutesCommand;
use Atannex\LaravelFlutterBridge\Console\ExportTranslationsCommand;
use Atannex\LaravelFlutterBridge\Console\GenerateFlutterModelCommand;
use Atannex\LaravelFlutterBridge\Console\InstallBridgeCommand;
use Atannex\LaravelFlutterBridge\Console\ListAppsCommand;
use Atannex\LaravelFlutterBridge\Console\ListConnectionsCommand;
use Atannex\LaravelFlutterBridge\Console\PruneConnectionsCommand;
use Atannex\LaravelFlutterBridge\Console\RevokeConnectionsCommand;
use Atannex\LaravelFlutterBridge\Console\SendConnectionCommand;
use Atannex\LaravelFlutterBridge\Console\UpdateAppCommand;
use Atannex\LaravelFlutterBridge\Http\Middleware\EnsureFlutterAppVersion;
use Atannex\LaravelFlutterBridge\Http\Middleware\EnsureFlutterHeaders;
use Atannex\LaravelFlutterBridge\Http\Middleware\FormatFlutterResponse;
use Atannex\LaravelFlutterBridge\Http\Middleware\ResolveFlutterApp;
use Atannex\LaravelFlutterBridge\Http\Middleware\TrackFlutterConnection;
use Atannex\LaravelFlutterBridge\Models\FlutterConnection;
use Atannex\LaravelFlutterBridge\Notifications\Fcm\FcmAccessTokenProvider;
use Atannex\LaravelFlutterBridge\Notifications\FlutterPushChannel;
use Atannex\LaravelFlutterBridge\Services\FlutterAppManager;
use Atannex\LaravelFlutterBridge\Services\FlutterBridgeManager;
use Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Atannex\LaravelFlutterBridge\Services\FlutterTokenManager;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class LaravelFlutterBridgeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge package configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/flutter-bridge.php',
            'flutter-bridge'
        );

        // Bind connection-management services
        $this->app->singleton(FlutterTokenManager::class);
        $this->app->singleton(FlutterAppManager::class);
        $this->app->singleton(FlutterConnectionManager::class);
        $this->app->singleton(FlutterPresenceManager::class);
        $this->app->singleton(FcmAccessTokenProvider::class);

        // Bind main bridge manager
        $this->app->singleton('flutter.bridge', function ($app) {
            return new FlutterBridgeManager($app);
        });
        $this->app->alias('flutter.bridge', FlutterBridgeManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallBridgeCommand::class,
                BridgeStatusCommand::class,
                ExportRoutesCommand::class,
                GenerateFlutterModelCommand::class,
                BroadcastTestEventCommand::class,
                ExportTranslationsCommand::class,
                ListAppsCommand::class,
                CreateAppCommand::class,
                UpdateAppCommand::class,
                DeleteAppCommand::class,
                ListConnectionsCommand::class,
                RevokeConnectionsCommand::class,
                SendConnectionCommand::class,
                PruneConnectionsCommand::class,
                ExportConfigCommand::class,
            ]);

            // Publish config
            $this->publishes([
                __DIR__ . '/../config/flutter-bridge.php' => config_path('flutter-bridge.php'),
            ], 'flutter-bridge-config');

            // Publish migrations
            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'flutter-bridge-migrations');
        }

        // Load migrations directly
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register route middleware aliases (before routes are loaded)
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('flutter.headers', EnsureFlutterHeaders::class);
        $router->aliasMiddleware('flutter.response', FormatFlutterResponse::class);
        $router->aliasMiddleware('flutter.app', ResolveFlutterApp::class);
        $router->aliasMiddleware('flutter.version', EnsureFlutterAppVersion::class);
        $router->aliasMiddleware('flutter.connection', TrackFlutterConnection::class);

        // Named rate limiters referenced by throttle:<name> on the bridge routes
        $this->registerRateLimiters();

        // Load API routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Register notification channel driver
        if ($this->app->bound(ChannelManager::class)) {
            $this->app->make(ChannelManager::class)->extend('flutter_push', function () {
                return new FlutterPushChannel($this->app->make(FcmAccessTokenProvider::class));
            });
        }

        // Register response macros
        $this->registerResponseMacros();

        // Envelope HTTP errors raised outside the route pipeline (e.g. maintenance mode)
        $this->registerExceptionRendering();

        // Private channel authorization for connection sessions and users
        $this->registerBroadcastChannels();
    }

    /**
     * Register the named rate limiters used by the bridge routes.
     *
     * Limits are read from flutter-bridge.rate_limits on every request, so a
     * value of 0 / null disables the corresponding limiter.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('flutter-auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return self::limits([
                self::perMinute('rate_limits.auth.per_minute', 'flutter-auth:' . $email . '|' . $request->ip()),
                self::perMinute('rate_limits.auth.per_ip_per_minute', 'flutter-auth-ip:' . $request->ip()),
            ]);
        });

        RateLimiter::for('flutter-2fa', function (Request $request) {
            return self::limits([
                self::perMinute('rate_limits.two_factor.per_minute', 'flutter-2fa:' . $request->ip()),
            ]);
        });

        RateLimiter::for('flutter-refresh', function (Request $request) {
            return self::limits([
                self::perMinute('rate_limits.refresh.per_minute', 'flutter-refresh:' . $request->ip()),
            ]);
        });

        RateLimiter::for('flutter-connect', function (Request $request) {
            $deviceId = (string) $request->input('device_id', '');

            return self::limits([
                self::perMinute('rate_limits.connections.connect_per_minute', 'flutter-connect:' . $deviceId . '|' . $request->ip()),
            ]);
        });

        RateLimiter::for('flutter-heartbeat', function (Request $request) {
            $connection = $request->route('connection');
            $id = $connection instanceof FlutterConnection ? (string) $connection->getKey() : (string) $connection;

            return self::limits([
                self::perMinute('rate_limits.connections.heartbeat_per_minute', 'flutter-heartbeat:' . ($id !== '' ? $id : $request->ip())),
            ]);
        });

        RateLimiter::for('flutter-api', function (Request $request) {
            $user = BridgeRequest::user($request);
            $key = $user !== null ? 'user:' . $user->getAuthIdentifier() : 'ip:' . $request->ip();

            return self::limits([
                self::perMinute('rate_limits.api.per_minute', 'flutter-api:' . $key),
            ]);
        });
    }

    /**
     * Build a per-minute limit from config, or null when it is disabled.
     */
    protected static function perMinute(string $configKey, string $key): ?Limit
    {
        $value = config('flutter-bridge.' . $configKey);

        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return Limit::perMinute((int) $value)->by($key);
    }

    /**
     * Collapse the enabled limits; no limits means unlimited.
     *
     * @param array<int, Limit|null> $limits
     * @return Limit|list<Limit>
     */
    protected static function limits(array $limits): Limit|array
    {
        $limits = array_values(array_filter($limits));

        return match (count($limits)) {
            0 => Limit::none(),
            1 => $limits[0],
            default => $limits,
        };
    }

    /**
     * Register convenient response macros on the Response factory.
     */
    protected function registerResponseMacros(): void
    {
        Response::macro('flutterSuccess', function (mixed $data = null, string $message = 'Success', int $status = 200, array $meta = []) {
            return FlutterResponse::success($data, $message, $status, $meta);
        });

        Response::macro('flutterError', function (string $message = 'Error', int $status = 400, mixed $errors = null, array $meta = [], ?string $errorCode = null) {
            return FlutterResponse::error($message, $status, $errors, $meta, $errorCode);
        });

        Response::macro('flutterPaginated', function ($paginator, string $message = 'Success', array $meta = []) {
            return FlutterResponse::paginated($paginator, $message, $meta);
        });
    }

    /**
     * Render HTTP exceptions on bridge routes with the envelope and an error code.
     *
     * Exceptions thrown inside the route pipeline are also wrapped by
     * FormatFlutterResponse; this covers global middleware such as
     * PreventRequestsDuringMaintenance (503 maintenance) and unknown routes.
     */
    protected function registerExceptionRendering(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(function (HttpExceptionInterface $e, Request $request) {
                if (! BridgeRequest::isBridgeRequest($request)) {
                    return null;
                }

                $status  = $e->getStatusCode();
                $message = $e->getMessage() !== ''
                    ? $e->getMessage()
                    : (SymfonyResponse::$statusTexts[$status] ?? 'Request failed');

                if ($status >= 500 && $status !== 503 && ! config('app.debug')) {
                    $message = 'Server Error';
                }

                $response = FlutterResponse::error(
                    $message,
                    $status,
                    null,
                    [],
                    FlutterResponse::defaultErrorCode($status)
                );

                foreach ($e->getHeaders() as $name => $value) {
                    $response->headers->set((string) $name, is_array($value) ? $value : (string) $value);
                }

                return EnsureFlutterHeaders::decorate($response);
            });
        });
    }

    /**
     * Register channel authorization callbacks used by the Flutter client.
     */
    protected function registerBroadcastChannels(): void
    {
        if (! config('flutter-bridge.broadcasting.enabled', true)
            || ! config('flutter-bridge.broadcasting.register_channels', true)) {
            return;
        }

        // Registered lazily, the first time the broadcast manager is resolved,
        // so the driver is never instantiated on requests that do not broadcast.
        $this->callAfterResolving(BroadcastFactory::class, function ($broadcast) {
            try {
                $broadcast->channel('flutter.connections.{connection}', function ($user, string $connection) {
                    return FlutterConnection::query()
                        ->whereKey($connection)
                        ->where('user_id', $user->getAuthIdentifier())
                        ->where('status', '!=', FlutterConnection::STATUS_REVOKED)
                        ->exists();
                });

                $broadcast->channel('users.{id}', function ($user, $id) {
                    return (string) $user->getAuthIdentifier() === (string) $id;
                });
            } catch (Throwable $e) {
                // Broadcasting is not configured (e.g. missing driver credentials or SDK);
                // channel authorization is simply unavailable.
                if ($this->app->bound('log')) {
                    $this->app['log']->debug('Flutter bridge broadcast channels not registered: ' . $e->getMessage());
                }
            }
        });
    }
}
