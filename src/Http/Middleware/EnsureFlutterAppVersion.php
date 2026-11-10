<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Middleware;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Services\FlutterAppManager;
use Atannex\LaravelFlutterBridge\Support\BridgeRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects outdated clients with 426 upgrade_required (alias: flutter.version).
 */
class EnsureFlutterAppVersion
{
    /**
     * Route names exempt from the version check so outdated clients can still
     * discover the update URL.
     *
     * @var list<string>
     */
    protected const EXEMPT_ROUTES = [
        'flutter.ping',
        'flutter.handshake',
    ];

    public function __construct(
        protected FlutterAppManager $apps
    ) {}

    /**
     * Handle an incoming request.
     *
     * @throws FlutterBridgeException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if ($routeName !== null && in_array($routeName, self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        $version = $request->header('X-Flutter-App-Version');
        $requirement = $this->apps->upgradeRequirement(
            BridgeRequest::app($request),
            is_string($version) ? trim($version) : null
        );

        if ($requirement !== null) {
            throw FlutterBridgeException::upgradeRequired($requirement);
        }

        return $next($request);
    }
}
