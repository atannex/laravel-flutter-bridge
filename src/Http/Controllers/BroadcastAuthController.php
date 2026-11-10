<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Controllers;

use Atannex\LaravelFlutterBridge\Exceptions\FlutterBridgeException;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpFoundation\Response;

class BroadcastAuthController extends Controller
{
    /**
     * Authenticate Flutter client for private / presence broadcasting channels.
     * Uses the authenticated user from the Bearer token.
     *
     * The broadcaster's signature is returned enveloped as data: {auth, channel_data}.
     */
    public function authenticate(Request $request): JsonResponse
    {
        if (!config('flutter-bridge.broadcasting.enabled', true)) {
            throw FlutterBridgeException::featureDisabled('broadcasting');
        }

        $request->validate([
            'channel_name' => ['required', 'string'],
            'socket_id' => ['required', 'string'],
        ]);

        $result = Broadcast::auth($request);

        if ($result instanceof Response) {
            $decoded = json_decode((string) $result->getContent(), true);
            $result = is_array($decoded) ? $decoded : [];
        }

        $payload = is_array($result) ? $result : [];

        if (isset($payload['channel_data']) && !is_string($payload['channel_data'])) {
            $payload['channel_data'] = json_encode($payload['channel_data']);
        }

        return FlutterResponse::success([
            'auth' => $payload['auth'] ?? null,
            'channel_data' => $payload['channel_data'] ?? null,
        ], 'Channel authorized');
    }
}
