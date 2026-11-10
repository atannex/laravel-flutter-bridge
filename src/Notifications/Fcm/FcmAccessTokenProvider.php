<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Notifications\Fcm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mints OAuth2 access tokens for the FCM HTTP v1 API from a Google service account.
 *
 * Credentials come from flutter-bridge.notifications.fcm_credentials (a path to
 * the service-account JSON file, the raw JSON or base64 JSON). A signed RS256
 * JWT is exchanged at the token endpoint and the resulting access token is
 * cached until shortly before it expires. When no credentials are configured,
 * the static fcm_bearer_token is used as a fallback (testing only).
 */
class FcmAccessTokenProvider
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public const MODE_SERVICE_ACCOUNT = 'service_account';

    public const MODE_STATIC_TOKEN = 'static_token';

    public const MODE_NONE = 'none';

    /**
     * Seconds of validity kept in reserve before a cached token is renewed.
     */
    protected const EXPIRY_MARGIN = 60;

    /**
     * Parsed credentials, memoized per raw config value.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $parsed = [];

    /**
     * How push notifications are authenticated with the current configuration.
     */
    public function mode(): string
    {
        if ($this->rawCredentials() !== null) {
            return self::MODE_SERVICE_ACCOUNT;
        }

        if ($this->staticToken() !== null) {
            return self::MODE_STATIC_TOKEN;
        }

        return self::MODE_NONE;
    }

    /**
     * Whether credentials or a static bearer token are configured.
     */
    public function isConfigured(): bool
    {
        return $this->mode() !== self::MODE_NONE;
    }

    /**
     * The parsed and validated service-account credentials, or null when none are configured.
     *
     * @return array<string, mixed>|null
     *
     * @throws FcmException
     */
    public function credentials(): ?array
    {
        $raw = $this->rawCredentials();

        if ($raw === null) {
            return null;
        }

        $memoKey = is_string($raw) ? hash('sha256', $raw) : hash('sha256', (string) json_encode($raw));

        return $this->parsed[$memoKey] ??= $this->validate($this->decode($raw));
    }

    /**
     * The Firebase project id: the configured one, else the credentials' project_id.
     *
     * @throws FcmException
     */
    public function projectId(): ?string
    {
        $configured = config('flutter-bridge.notifications.fcm_project_id');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $credentials = $this->credentials();
        $projectId = $credentials['project_id'] ?? null;

        return is_string($projectId) && $projectId !== '' ? $projectId : null;
    }

    /**
     * A bearer token for the FCM API (cached service-account token or the static fallback).
     *
     * @throws FcmException
     */
    public function accessToken(): string
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            $static = $this->staticToken();

            if ($static === null) {
                throw new FcmException('FCM is not configured: set FCM_CREDENTIALS to a service-account JSON file.');
            }

            return $static;
        }

        $cacheKey = $this->cacheKey($credentials);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        ['token' => $token, 'expires_in' => $expiresIn] = $this->fetchAccessToken($credentials);

        $ttl = $expiresIn - self::EXPIRY_MARGIN;

        if ($ttl > 0) {
            Cache::put($cacheKey, $token, $ttl);
        }

        return $token;
    }

    /**
     * Drop the cached access token (e.g. after FCM answered 401).
     */
    public function forgetAccessToken(): void
    {
        try {
            $credentials = $this->credentials();
        } catch (FcmException) {
            return;
        }

        if ($credentials !== null) {
            Cache::forget($this->cacheKey($credentials));
        }
    }

    /**
     * Build the signed RS256 JWT assertion for the OAuth2 jwt-bearer grant.
     *
     * @param  array<string, mixed>  $credentials
     *
     * @throws FcmException
     */
    public function createJwt(array $credentials, ?int $now = null): string
    {
        $credentials = $this->validate($credentials);
        $now ??= time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        if (isset($credentials['private_key_id']) && is_string($credentials['private_key_id']) && $credentials['private_key_id'] !== '') {
            $header['kid'] = $credentials['private_key_id'];
        }

        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $this->tokenUri($credentials),
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $input = self::base64Url((string) json_encode($header, JSON_UNESCAPED_SLASHES))
            .'.'
            .self::base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        $signature = '';

        if (! openssl_sign($input, $signature, (string) $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new FcmException('Unable to sign the FCM JWT with the service-account private_key: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return $input.'.'.self::base64Url($signature);
    }

    /**
     * Describe the configuration without any network call (used by flutter:status).
     *
     * @return array{mode: string, configured: bool, valid: bool, project_id: ?string, client_email: ?string, error: ?string}
     */
    public function status(): array
    {
        $status = [
            'mode' => $this->mode(),
            'configured' => $this->isConfigured(),
            'valid' => false,
            'project_id' => null,
            'client_email' => null,
            'error' => null,
        ];

        try {
            $credentials = $this->credentials();
            $status['project_id'] = $this->projectId();
            $status['client_email'] = is_array($credentials) ? (string) $credentials['client_email'] : null;

            if ($status['mode'] === self::MODE_NONE) {
                return $status;
            }

            if ($status['project_id'] === null) {
                $status['error'] = 'No project id: set FCM_PROJECT_ID or use credentials containing project_id.';

                return $status;
            }

            $status['valid'] = true;
        } catch (FcmException $e) {
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    /**
     * Exchange a signed JWT for an access token.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{token: string, expires_in: int}
     *
     * @throws FcmException
     */
    protected function fetchAccessToken(array $credentials): array
    {
        $uri = $this->tokenUri($credentials);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('flutter-bridge.notifications.fcm_timeout', 10))
                ->post($uri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->createJwt($credentials),
                ]);
        } catch (FcmException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new FcmException("FCM OAuth token request to [{$uri}] failed: {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            $reason = $response->json('error_description') ?? $response->json('error') ?? mb_substr($response->body(), 0, 200);

            throw new FcmException("FCM OAuth token request failed (HTTP {$response->status()}): ".(is_string($reason) ? $reason : json_encode($reason)));
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new FcmException('FCM OAuth token response did not contain an access_token.');
        }

        $expiresIn = $response->json('expires_in');

        return [
            'token' => $token,
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : 3600,
        ];
    }

    /**
     * Turn the configured value into an array.
     *
     * @param  string|array<string, mixed>  $raw
     * @return array<string, mixed>
     *
     * @throws FcmException
     */
    protected function decode(string|array $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $value = trim($raw);

        if (str_starts_with($value, '{')) {
            return $this->decodeJson($value, 'FCM_CREDENTIALS JSON');
        }

        if (is_file($value)) {
            if (! is_readable($value)) {
                throw new FcmException("FCM credentials file [{$value}] is not readable.");
            }

            $contents = file_get_contents($value);

            if ($contents === false) {
                throw new FcmException("FCM credentials file [{$value}] could not be read.");
            }

            return $this->decodeJson($contents, "FCM credentials file [{$value}]");
        }

        $decoded = base64_decode($value, true);

        if ($decoded !== false && str_starts_with(ltrim($decoded), '{')) {
            return $this->decodeJson($decoded, 'FCM_CREDENTIALS base64 JSON');
        }

        if (str_ends_with(strtolower($value), '.json') || str_contains($value, DIRECTORY_SEPARATOR)) {
            throw new FcmException("FCM credentials file [{$value}] does not exist.");
        }

        throw new FcmException('FCM_CREDENTIALS must be a path to a service-account JSON file, the JSON itself or base64-encoded JSON.');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FcmException
     */
    protected function decodeJson(string $json, string $source): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new FcmException("{$source} is not valid JSON: ".json_last_error_msg());
        }

        return $data;
    }

    /**
     * Ensure the credentials can sign tokens.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     *
     * @throws FcmException
     */
    protected function validate(array $credentials): array
    {
        if (isset($credentials['type']) && $credentials['type'] !== 'service_account') {
            throw new FcmException("FCM credentials must be a service account key (type \"service_account\"), got \"{$credentials['type']}\".");
        }

        foreach (['client_email', 'private_key'] as $field) {
            if (! isset($credentials[$field]) || ! is_string($credentials[$field]) || trim($credentials[$field]) === '') {
                throw new FcmException("FCM service-account credentials are missing \"{$field}\".");
            }
        }

        // Keys pasted into .env often carry literal "\n" sequences.
        $key = (string) $credentials['private_key'];

        if (! str_contains($key, "\n") && str_contains($key, '\\n')) {
            $credentials['private_key'] = $key = str_replace('\\n', "\n", $key);
        }

        if (openssl_pkey_get_private($key) === false) {
            throw new FcmException('FCM service-account private_key is not a valid PEM private key.');
        }

        return $credentials;
    }

    /**
     * The raw fcm_credentials value, or null when empty.
     *
     * @return string|array<string, mixed>|null
     */
    protected function rawCredentials(): string|array|null
    {
        $raw = config('flutter-bridge.notifications.fcm_credentials');

        if (is_array($raw)) {
            return $raw === [] ? null : $raw;
        }

        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    /**
     * The static fcm_bearer_token fallback, or null when empty.
     */
    protected function staticToken(): ?string
    {
        $token = config('flutter-bridge.notifications.fcm_bearer_token');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function tokenUri(array $credentials): string
    {
        $uri = $credentials['token_uri'] ?? null;

        return is_string($uri) && $uri !== '' ? $uri : self::DEFAULT_TOKEN_URI;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function cacheKey(array $credentials): string
    {
        return 'flutter-bridge:fcm-access-token:'.hash('sha256', (string) $credentials['client_email']);
    }

    protected static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
