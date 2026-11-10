<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Broadcasting;

/**
 * Decides whether bridge events should be broadcast and which connection
 * is advertised to Flutter clients.
 */
final class BroadcastGate
{
    /**
     * Drivers that never deliver to clients.
     *
     * @var list<string|null>
     */
    private const INERT_DRIVERS = [null, '', 'null', 'log'];

    /**
     * Whether bridge events should be broadcast right now.
     */
    public static function enabled(): bool
    {
        if (!config('flutter-bridge.broadcasting.enabled', true)) {
            return false;
        }

        return !in_array(self::defaultDriver(), self::INERT_DRIVERS, true);
    }

    /**
     * Driver of the default broadcasting connection.
     */
    public static function defaultDriver(): ?string
    {
        $default = config('broadcasting.default');

        if (!is_string($default) || $default === '') {
            return null;
        }

        $driver = config("broadcasting.connections.{$default}.driver");

        return is_string($driver) ? $driver : null;
    }

    /**
     * Client connection details for the handshake, or null when not advertisable.
     *
     * @return array<string, mixed>|null
     */
    public static function clientOptions(): ?array
    {
        if (!config('flutter-bridge.broadcasting.enabled', true)) {
            return null;
        }

        $name = config('flutter-bridge.broadcasting.expose') ?: config('broadcasting.default');

        if (!is_string($name) || $name === '') {
            return null;
        }

        $connection = config("broadcasting.connections.{$name}");

        if (!is_array($connection) || !in_array($connection['driver'] ?? null, ['reverb', 'pusher'], true)) {
            return null;
        }

        $driver = (string) $connection['driver'];
        $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
        $client = (array) config('flutter-bridge.broadcasting.client', []);
        $cluster = $options['cluster'] ?? null;

        $host = $client['host'] ?? null;
        if ($host === null || $host === '') {
            $host = $driver === 'pusher' && !empty($cluster) && empty($options['host'])
                ? "ws-{$cluster}.pusher.com"
                : ($options['host'] ?? null);
        }

        $scheme = ($client['scheme'] ?? null) ?: ($options['scheme'] ?? null);
        if (($scheme === null || $scheme === '') && array_key_exists('useTLS', $options)) {
            $scheme = $options['useTLS'] ? 'https' : 'http';
        }
        $scheme = $scheme ?: 'https';

        $port = ($client['port'] ?? null) ?: ($options['port'] ?? null);
        $port = $port !== null && $port !== '' ? (int) $port : ($scheme === 'https' ? 443 : 80);

        return [
            'driver' => $driver,
            'key' => $connection['key'] ?? null,
            'host' => $host !== null ? (string) $host : null,
            'port' => $port,
            'scheme' => (string) $scheme,
            'cluster' => $cluster !== null && $cluster !== '' ? (string) $cluster : null,
        ];
    }
}
