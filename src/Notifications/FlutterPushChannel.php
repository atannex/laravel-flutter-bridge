<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Notifications;

use Atannex\LaravelFlutterBridge\Events\FlutterPushSent;
use Atannex\LaravelFlutterBridge\Events\FlutterPushTokenInvalidated;
use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Atannex\LaravelFlutterBridge\Notifications\Fcm\FcmAccessTokenProvider;
use Atannex\LaravelFlutterBridge\Notifications\Fcm\FcmException;
use Atannex\LaravelFlutterBridge\Notifications\Messages\FlutterPushMessage;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The `flutter_push` notification channel (FCM HTTP v1).
 *
 * Messages are sent concurrently to every device token of the notifiable.
 * Tokens FCM reports as dead are cleared from flutter_devices and announced
 * with FlutterPushTokenInvalidated; a FlutterPushSent summary is fired after
 * each delivery. Per-token failures are logged and never thrown.
 */
class FlutterPushChannel
{
    public const FCM_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    public function __construct(
        protected ?FcmAccessTokenProvider $credentials = null
    ) {}

    /**
     * Send the given notification.
     *
     * @return array{sent: int, failed: int, invalidated: int}
     */
    public function send(mixed $notifiable, Notification $notification): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'invalidated' => 0];

        if (! method_exists($notification, 'toFlutterPush')) {
            return $summary;
        }

        /** @var FlutterPushMessage|array<string, mixed> $message */
        $message = $notification->toFlutterPush($notifiable);
        $payload = $message instanceof FlutterPushMessage ? $message->toArray() : (array) $message;

        $tokens = $this->resolveTokens($notifiable);

        if (empty($tokens)) {
            return $summary;
        }

        $provider = $this->credentials ?? app(FcmAccessTokenProvider::class);

        if (! $provider->isConfigured()) {
            $this->logOnly($notifiable, $tokens, $payload);

            return $summary;
        }

        try {
            $projectId = $provider->projectId();

            if ($projectId === null) {
                if ($provider->mode() === FcmAccessTokenProvider::MODE_STATIC_TOKEN) {
                    // A static token without FCM_PROJECT_ID keeps the historical log-only behaviour.
                    $this->logOnly($notifiable, $tokens, $payload);

                    return $summary;
                }

                throw new FcmException('No Firebase project id: set FCM_PROJECT_ID or use credentials containing project_id.');
            }

            $accessToken = $provider->accessToken();
        } catch (Throwable $e) {
            Log::error('FlutterPushChannel: FCM is misconfigured, push notification not sent: '.$e->getMessage());

            return $this->finish($notifiable, $notification, 0, count($tokens), []);
        }

        $endpoint = sprintf(self::FCM_ENDPOINT, rawurlencode($projectId));
        $results = $this->deliver($endpoint, $accessToken, $payload, $tokens);

        // An expired / revoked access token: drop it, mint a new one and retry once.
        $unauthorized = array_keys(array_filter(
            $results,
            fn ($result) => $result instanceof Response && $result->status() === 401
        ));

        if ($unauthorized !== []) {
            $provider->forgetAccessToken();

            try {
                $accessToken = $provider->accessToken();
                $retryTokens = array_map('strval', $unauthorized);
                $results = array_replace($results, $this->deliver($endpoint, $accessToken, $payload, $retryTokens));
            } catch (Throwable $e) {
                Log::warning('FlutterPushChannel: could not refresh the FCM access token: '.$e->getMessage());
            }
        }

        $sent = 0;
        $failed = 0;
        $invalidated = [];

        foreach ($results as $token => $result) {
            $token = (string) $token;

            if ($result instanceof Response && $result->successful()) {
                $sent++;

                continue;
            }

            if ($result instanceof Response && $this->isDeadToken($result)) {
                $this->invalidate($token);
                $invalidated[] = $token;

                continue;
            }

            $failed++;
            $this->logFailure($token, $result);
        }

        return $this->finish($notifiable, $notification, $sent, $failed, $invalidated);
    }

    /**
     * POST the message to every token, `fcm_concurrency` requests at a time.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $tokens
     * @return array<string, Response|Throwable>
     */
    protected function deliver(string $endpoint, string $accessToken, array $payload, array $tokens): array
    {
        $concurrency = max(1, (int) config('flutter-bridge.notifications.fcm_concurrency', 10));
        $timeout = (int) config('flutter-bridge.notifications.fcm_timeout', 10);
        $results = [];

        foreach (array_chunk($tokens, $concurrency) as $chunk) {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (string $token) => $pool->withToken($accessToken)
                        ->acceptJson()
                        ->timeout($timeout)
                        ->post($endpoint, ['message' => array_merge($payload, ['token' => $token])]),
                    $chunk
                ));
            } catch (Throwable $e) {
                $responses = array_fill(0, count($chunk), $e);
            }

            foreach ($chunk as $index => $token) {
                $result = $responses[$index] ?? null;

                $results[$token] = $result instanceof Response || $result instanceof Throwable
                    ? $result
                    : new RuntimeException('No response from FCM');
            }
        }

        return $results;
    }

    /**
     * Whether FCM reported the token as no longer valid.
     */
    protected function isDeadToken(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        $code = $this->errorCode($response);

        if ($code === 'UNREGISTERED' || $response->json('error.status') === 'UNREGISTERED') {
            return true;
        }

        if ($code === 'INVALID_ARGUMENT' || $response->json('error.status') === 'INVALID_ARGUMENT') {
            $message = $response->json('error.message');

            return is_string($message) && str_contains(strtolower($message), 'registration token');
        }

        return false;
    }

    /**
     * The FCM error code (error.details[].errorCode, else error.status).
     */
    protected function errorCode(Response $response): ?string
    {
        $details = $response->json('error.details');

        if (is_array($details)) {
            foreach ($details as $detail) {
                if (is_array($detail) && isset($detail['errorCode']) && is_string($detail['errorCode'])) {
                    return $detail['errorCode'];
                }
            }
        }

        $status = $response->json('error.status');

        return is_string($status) ? $status : null;
    }

    /**
     * Clear a dead token from the device registry and announce it.
     */
    protected function invalidate(string $token): void
    {
        $rowIds = [];

        try {
            FlutterDevice::query()->where('fcm_token', $token)->get()->each(function (FlutterDevice $device) use (&$rowIds) {
                $device->fcm_token = null;
                $device->save();
                $rowIds[] = (int) $device->getKey();
            });
        } catch (Throwable $e) {
            Log::warning('FlutterPushChannel: could not clear an invalid FCM token: '.$e->getMessage());
        }

        if ($rowIds === []) {
            event(new FlutterPushTokenInvalidated($token, null));

            return;
        }

        foreach ($rowIds as $rowId) {
            event(new FlutterPushTokenInvalidated($token, $rowId));
        }
    }

    /**
     * Log a non-fatal per-token failure.
     */
    protected function logFailure(string $token, Response|Throwable $result): void
    {
        $context = ['token' => $this->maskToken($token)];

        if ($result instanceof Response) {
            $context['status'] = $result->status();
            $context['fcm_error'] = $this->errorCode($result);
            $message = $result->json('error.message');
            $context['message'] = is_string($message) ? $message : mb_substr($result->body(), 0, 200);
        } else {
            $context['message'] = $result->getMessage();
        }

        Log::warning('FlutterPushChannel: FCM delivery failed ['.($context['fcm_error'] ?? 'exception').']', $context);
    }

    /**
     * Fire the delivery summary and return it.
     *
     * @param  list<string>  $invalidated
     * @return array{sent: int, failed: int, invalidated: int}
     */
    protected function finish(mixed $notifiable, Notification $notification, int $sent, int $failed, array $invalidated): array
    {
        event(new FlutterPushSent($notifiable, $notification, $sent, $failed, count($invalidated), $invalidated));

        return ['sent' => $sent, 'failed' => $failed, 'invalidated' => count($invalidated)];
    }

    /**
     * Log the payload instead of sending it (no credentials configured).
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $payload
     */
    protected function logOnly(mixed $notifiable, array $tokens, array $payload): void
    {
        Log::info('FlutterPushChannel: Dispatching push notification to '.count($tokens).' devices', [
            'notifiable_id' => is_object($notifiable) && method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
            'tokens' => $tokens,
            'payload' => $payload,
        ]);
    }

    /**
     * Shorten a token for logs.
     */
    protected function maskToken(string $token): string
    {
        return strlen($token) > 16 ? substr($token, 0, 12).'…' : $token;
    }

    /**
     * Resolve target push tokens from notifiable.
     *
     * @return list<string>
     */
    protected function resolveTokens(mixed $notifiable): array
    {
        $tokens = [];

        // Check custom routeNotificationFor
        if (is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            $tokens = (array) $notifiable->routeNotificationFor('flutter_push');
        }

        // Check bridge trait method
        if (empty($tokens) && is_object($notifiable) && method_exists($notifiable, 'getActiveFcmTokens')) {
            $tokens = (array) $notifiable->getActiveFcmTokens();
        }

        return array_values(array_unique(array_filter(
            $tokens,
            fn ($token) => is_string($token) && $token !== ''
        )));
    }
}
