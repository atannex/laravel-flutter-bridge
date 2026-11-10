<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Tests\Feature;

use Atannex\LaravelFlutterBridge\Events\FlutterPushSent;
use Atannex\LaravelFlutterBridge\Events\FlutterPushTokenInvalidated;
use Atannex\LaravelFlutterBridge\Models\FlutterDevice;
use Atannex\LaravelFlutterBridge\Notifications\Fcm\FcmAccessTokenProvider;
use Atannex\LaravelFlutterBridge\Notifications\Fcm\FcmException;
use Atannex\LaravelFlutterBridge\Notifications\FlutterPushChannel;
use Atannex\LaravelFlutterBridge\Notifications\Messages\FlutterPushMessage;
use Atannex\LaravelFlutterBridge\Tests\Fixtures\User;
use Atannex\LaravelFlutterBridge\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushChannelTest extends TestCase
{
    protected const OAUTH = 'https://oauth2.googleapis.com/token';

    protected static ?string $privateKey = null;

    protected static ?string $publicKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $this->assertNotFalse($key, 'Unable to generate a test RSA key');
            openssl_pkey_export($key, $pem);
            self::$privateKey = (string) $pem;
            self::$publicKey = (string) openssl_pkey_get_details($key)['key'];
        }
    }

    /**
     * @return array<string, string>
     */
    protected function serviceAccount(array $overrides = []): array
    {
        return array_merge([
            'type' => 'service_account',
            'project_id' => 'demo-project',
            'private_key_id' => 'kid-123',
            'private_key' => (string) self::$privateKey,
            'client_email' => 'push@demo-project.iam.gserviceaccount.com',
            'token_uri' => self::OAUTH,
        ], $overrides);
    }

    protected function useServiceAccount(array $overrides = []): void
    {
        config()->set('flutter-bridge.notifications.fcm_credentials', json_encode($this->serviceAccount($overrides)));
    }

    protected function userWithTokens(string ...$tokens): User
    {
        $user = $this->createUser();

        foreach ($tokens as $i => $token) {
            $user->registerFlutterDevice("device-{$i}", ['fcm_token' => $token, 'platform' => 'android']);
        }

        return $user;
    }

    protected function notification(?FlutterPushMessage $message = null): Notification
    {
        $message ??= (new FlutterPushMessage)->title('Hello')->body('World');

        return new class($message) extends Notification
        {
            public function __construct(public FlutterPushMessage $message) {}

            public function via(mixed $notifiable): array
            {
                return ['flutter_push'];
            }

            public function toFlutterPush(mixed $notifiable): FlutterPushMessage
            {
                return $this->message;
            }
        };
    }

    /**
     * @return array{sent: int, failed: int, invalidated: int}
     */
    protected function push(User $user, ?Notification $notification = null): array
    {
        /** @var FlutterPushChannel $channel */
        $channel = $this->app->make(ChannelManager::class)->driver('flutter_push');

        return $channel->send($user, $notification ?? $this->notification());
    }

    protected function fcmUrl(string $project = 'demo-project'): string
    {
        return "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";
    }

    public function test_jwt_is_well_formed_and_verifiable(): void
    {
        $provider = $this->app->make(FcmAccessTokenProvider::class);
        $jwt = $provider->createJwt($this->serviceAccount(), 1_700_000_000);

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
        $header = json_decode($decode($parts[0]), true);
        $claims = json_decode($decode($parts[1]), true);

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-123'], $header);
        $this->assertSame([
            'iss' => 'push@demo-project.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => self::OAUTH,
            'iat' => 1_700_000_000,
            'exp' => 1_700_003_600,
        ], $claims);

        $this->assertSame(1, openssl_verify(
            $parts[0].'.'.$parts[1],
            (string) $decode($parts[2]),
            (string) self::$publicKey,
            OPENSSL_ALGO_SHA256
        ));
    }

    public function test_access_token_is_cached_across_sends(): void
    {
        $this->useServiceAccount();
        Event::fake([FlutterPushSent::class]);
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.minted', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-project/messages/1']),
        ]);

        $user = $this->userWithTokens('token-a', 'token-b');

        $this->assertSame(['sent' => 2, 'failed' => 0, 'invalidated' => 0], $this->push($user));
        $this->assertSame(['sent' => 2, 'failed' => 0, 'invalidated' => 0], $this->push($user));

        $oauthCalls = Http::recorded(fn (Request $request) => $request->url() === self::OAUTH);
        $this->assertCount(1, $oauthCalls);

        [$oauth] = $oauthCalls->first();
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $oauth['grant_type']);
        $this->assertCount(3, explode('.', (string) $oauth['assertion']));

        Http::assertSent(fn (Request $request) => $request->url() === $this->fcmUrl()
            && $request->hasHeader('Authorization', 'Bearer ya29.minted')
            && $request['message']['token'] === 'token-b'
            && $request['message']['notification']['title'] === 'Hello');
        $this->assertCount(4, Http::recorded(fn (Request $request) => $request->url() === $this->fcmUrl()));

        Event::assertDispatched(FlutterPushSent::class, fn (FlutterPushSent $event) => $event->sent === 2 && $event->failed === 0);
    }

    public function test_unauthorized_refreshes_the_token_and_retries_once(): void
    {
        $this->useServiceAccount();
        Http::fake([
            self::OAUTH => Http::sequence()
                ->push(['access_token' => 'stale-token', 'expires_in' => 3600])
                ->push(['access_token' => 'fresh-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => function (Request $request) {
                return $request->hasHeader('Authorization', 'Bearer stale-token')
                    ? Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED', 'message' => 'expired']], 401)
                    : Http::response(['name' => 'projects/demo-project/messages/2']);
            },
        ]);

        $user = $this->userWithTokens('token-a');

        $this->assertSame(['sent' => 1, 'failed' => 0, 'invalidated' => 0], $this->push($user));
        $this->assertCount(2, Http::recorded(fn (Request $request) => $request->url() === self::OAUTH));
        Http::assertSent(fn (Request $request) => $request->url() === $this->fcmUrl()
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    }

    public function test_unregistered_token_is_cleared_and_event_fired(): void
    {
        $this->useServiceAccount();
        Event::fake([FlutterPushTokenInvalidated::class, FlutterPushSent::class]);
        Http::fake([
            self::OAUTH => Http::response(['access_token' => 'ya29.minted', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => function (Request $request) {
                return match ($request['message']['token']) {
                    'dead-token' => Http::response(['error' => [
                        'code' => 404,
                        'status' => 'NOT_FOUND',
                        'message' => 'Requested entity was not found.',
                        'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
                    ]], 404),
                    'bad-token' => Http::response(['error' => [
                        'code' => 400,
                        'status' => 'INVALID_ARGUMENT',
                        'message' => 'The registration token is not a valid FCM registration token',
                    ]], 400),
                    'quota-token' => Http::response(['error' => [
                        'code' => 429,
                        'status' => 'RESOURCE_EXHAUSTED',
                        'message' => 'Quota exceeded',
                        'details' => [['errorCode' => 'QUOTA_EXCEEDED']],
                    ]], 429),
                    default => Http::response(['name' => 'ok']),
                };
            },
        ]);

        $user = $this->userWithTokens('live-token', 'dead-token', 'bad-token', 'quota-token');
        $deadRow = FlutterDevice::query()->where('fcm_token', 'dead-token')->sole();

        $summary = $this->push($user);

        $this->assertSame(['sent' => 1, 'failed' => 1, 'invalidated' => 2], $summary);
        $this->assertNull($deadRow->fresh()?->fcm_token);
        $this->assertSame(0, FlutterDevice::query()->where('fcm_token', 'bad-token')->count());
        $this->assertSame(1, FlutterDevice::query()->where('fcm_token', 'live-token')->count());
        $this->assertSame(['live-token', 'quota-token'], $user->getActiveFcmTokens());

        Event::assertDispatched(FlutterPushTokenInvalidated::class, fn (FlutterPushTokenInvalidated $event) => $event->token === 'dead-token' && $event->deviceRowId === $deadRow->id);
        Event::assertDispatchedTimes(FlutterPushTokenInvalidated::class, 2);
        Event::assertDispatched(FlutterPushSent::class, fn (FlutterPushSent $event) => $event->sent === 1
            && $event->failed === 1
            && $event->invalidated === 2
            && $event->invalidatedTokens === ['dead-token', 'bad-token']);
    }

    public function test_static_bearer_token_fallback(): void
    {
        config()->set('flutter-bridge.notifications.fcm_project_id', 'static-project');
        config()->set('flutter-bridge.notifications.fcm_bearer_token', 'static-token');
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        $this->assertSame(['sent' => 1, 'failed' => 0, 'invalidated' => 0], $this->push($this->userWithTokens('token-a')));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === $this->fcmUrl('static-project')
            && $request->hasHeader('Authorization', 'Bearer static-token'));
    }

    public function test_without_credentials_the_payload_is_only_logged(): void
    {
        Http::fake();
        Log::spy();

        $this->assertSame(['sent' => 0, 'failed' => 0, 'invalidated' => 0], $this->push($this->userWithTokens('token-a')));

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'Dispatching push notification to 1 devices')
            && $context['tokens'] === ['token-a'])->once();
    }

    public function test_invalid_credentials_are_logged_not_thrown(): void
    {
        config()->set('flutter-bridge.notifications.fcm_credentials', '/does/not/exist/service-account.json');
        Http::fake();
        Log::spy();

        $this->assertSame(['sent' => 0, 'failed' => 1, 'invalidated' => 0], $this->push($this->userWithTokens('token-a')));

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'does not exist'))->once();
    }

    public function test_credentials_from_file_and_base64(): void
    {
        $provider = $this->app->make(FcmAccessTokenProvider::class);
        $path = (string) tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, json_encode($this->serviceAccount()));

        try {
            config()->set('flutter-bridge.notifications.fcm_credentials', $path);
            $this->assertSame('demo-project', $provider->projectId());

            config()->set('flutter-bridge.notifications.fcm_credentials', base64_encode((string) json_encode($this->serviceAccount(['project_id' => 'b64-project']))));
            $this->assertSame('b64-project', $provider->projectId());

            config()->set('flutter-bridge.notifications.fcm_project_id', 'override');
            $this->assertSame('override', $provider->projectId());
        } finally {
            @unlink($path);
        }
    }

    public function test_invalid_credentials_have_clear_errors(): void
    {
        $provider = $this->app->make(FcmAccessTokenProvider::class);

        $cases = [
            '{not json' => 'not valid JSON',
            (string) json_encode(['type' => 'service_account', 'client_email' => 'a@b.c']) => 'missing "private_key"',
            (string) json_encode($this->serviceAccount(['private_key' => 'garbage'])) => 'not a valid PEM private key',
            (string) json_encode(['type' => 'authorized_user']) => 'must be a service account',
            'definitely-not-credentials' => 'must be a path',
        ];

        foreach ($cases as $value => $expected) {
            config()->set('flutter-bridge.notifications.fcm_credentials', $value);

            try {
                $provider->credentials();
                $this->fail("No exception for [{$value}]");
            } catch (FcmException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function test_message_payload_mapping(): void
    {
        $message = (new FlutterPushMessage)
            ->title('Order shipped')
            ->body('On its way')
            ->image('https://example.test/i.png')
            ->sound('chime.caf')
            ->badge(3)
            ->channelId('orders')
            ->clickAction('OPEN_ORDER')
            ->data(['order_id' => 42, 'screen' => '/orders']);

        $this->assertSame([
            'notification' => ['title' => 'Order shipped', 'body' => 'On its way', 'image' => 'https://example.test/i.png'],
            'data' => ['order_id' => '42', 'screen' => '/orders', 'click_action' => 'OPEN_ORDER'],
            'android' => ['notification' => ['channel_id' => 'orders', 'sound' => 'chime.caf']],
            'apns' => ['payload' => ['aps' => ['sound' => 'chime.caf', 'badge' => 3]]],
        ], $message->toArray());

        $minimal = (new FlutterPushMessage)->title('Hi')->body('There')->sound(null)->toArray();
        $this->assertSame(['notification' => ['title' => 'Hi', 'body' => 'There']], $minimal);
    }

    public function test_requests_are_sent_in_concurrent_chunks(): void
    {
        config()->set('flutter-bridge.notifications.fcm_concurrency', 2);
        config()->set('flutter-bridge.notifications.fcm_project_id', 'static-project');
        config()->set('flutter-bridge.notifications.fcm_bearer_token', 'static-token');
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        $summary = $this->push($this->userWithTokens('t1', 't2', 't3', 't4', 't5'));

        $this->assertSame(['sent' => 5, 'failed' => 0, 'invalidated' => 0], $summary);
        Http::assertSentCount(5);
    }

    public function test_status_command_reports_fcm_configuration(): void
    {
        $this->artisan('flutter:status')->expectsOutputToContain('Not configured')->assertSuccessful();

        $this->useServiceAccount();
        $this->artisan('flutter:status')
            ->expectsOutputToContain('Project demo-project as push@demo-project.iam.gserviceaccount.com')
            ->assertSuccessful();

        config()->set('flutter-bridge.notifications.fcm_credentials', '{broken');
        $this->artisan('flutter:status')->expectsOutputToContain('Invalid')->assertSuccessful();
    }
}
