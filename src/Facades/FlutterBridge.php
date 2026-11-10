<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Http\JsonResponse success(mixed $data = null, string $message = 'Success', int $statusCode = 200, array $meta = [])
 * @method static \Illuminate\Http\JsonResponse error(string $message = 'An error occurred', int $statusCode = 400, mixed $errors = null, array $meta = [], ?string $errorCode = null)
 * @method static \Illuminate\Http\JsonResponse paginated(\Illuminate\Contracts\Pagination\Paginator $paginator, string $message = 'Data retrieved successfully', array $meta = [])
 * @method static \Atannex\LaravelFlutterBridge\Models\FlutterDevice registerDevice(int|string|null $userId, string $deviceId, array $attributes = [])
 * @method static \Atannex\LaravelFlutterBridge\Services\FlutterConnectionManager connections()
 * @method static \Atannex\LaravelFlutterBridge\Services\FlutterAppManager apps()
 * @method static \Atannex\LaravelFlutterBridge\Services\FlutterTokenManager tokens()
 * @method static string version()
 * @method static bool featureEnabled(string $feature)
 * @method static array<string, bool> features()
 *
 * @see \Atannex\LaravelFlutterBridge\Services\FlutterBridgeManager
 */
class FlutterBridge extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'flutter.bridge';
    }
}
