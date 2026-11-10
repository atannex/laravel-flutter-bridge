<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests;

use Atannex\LaravelFlutterBridge\LaravelFlutterBridgeServiceProvider;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            LaravelFlutterBridgeServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.name', 'Bridge Test');
        $app['config']->set('app.url', 'https://api.example.test');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('flutter-bridge.auth.user_model', User::class);
        $app['config']->set('broadcasting.default', 'null');

        // Generous limits so the suite never trips a limiter by accident;
        // rate limiting tests lower them explicitly.
        $app['config']->set('flutter-bridge.rate_limits', [
            'auth' => ['per_minute' => 1000, 'per_ip_per_minute' => 1000],
            'two_factor' => ['per_minute' => 1000],
            'refresh' => ['per_minute' => 1000],
            'connections' => ['connect_per_minute' => 1000, 'heartbeat_per_minute' => 1000],
            'api' => ['per_minute' => 1000],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../vendor/laravel/sanctum/database/migrations');
    }

    /**
     * Create a test user.
     */
    protected function createUser(string $email = 'jane@example.test', string $password = 'secret-password'): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Jane',
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        return $user;
    }

    /**
     * Bridge endpoint URL.
     */
    protected function bridgeUrl(string $path): string
    {
        return '/api/flutter/' . ltrim($path, '/');
    }

    /**
     * Forget resolved guards so each request re-authenticates from its headers.
     */
    protected function freshAuth(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /**
     * Perform a JSON request against the bridge with fresh authentication state.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function bridge(string $method, string $path, array $data = [], array $headers = []): TestResponse
    {
        $this->freshAuth();

        return $this->json($method, $this->bridgeUrl($path), $data, $headers);
    }

    /**
     * Connect a device and return the connection id.
     *
     * @param array<string, string> $headers
     */
    protected function connectDevice(string $deviceId = 'device-1', array $headers = [], array $extra = []): string
    {
        $response = $this->bridge('POST', 'connections/connect', array_merge([
            'device_id' => $deviceId,
            'platform' => 'android',
            'app_version' => '1.4.2',
            'device_name' => 'Pixel 9',
            'os_version' => '15',
        ], $extra), $headers);

        $response->assertSuccessful();

        return (string) $response->json('data.connection.id');
    }

    /**
     * Log in and return the data payload.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function login(string $email = 'jane@example.test', array $headers = [], array $extra = []): array
    {
        $response = $this->bridge('POST', 'auth/login', array_merge([
            'email' => $email,
            'password' => 'secret-password',
            'device_name' => 'Pixel 9',
        ], $extra), $headers);

        $response->assertOk();

        return (array) $response->json('data');
    }
}
