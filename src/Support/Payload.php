<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Support;

use stdClass;

/**
 * JSON shaping helpers for the wire contract.
 */
final class Payload
{
    /**
     * Ensure a map serializes as a JSON object ("{}") even when empty.
     *
     * @param array<string, mixed>|null $value
     * @return array<string, mixed>|stdClass
     */
    public static function object(?array $value): array|stdClass
    {
        return empty($value) ? new stdClass() : $value;
    }

    /**
     * Format a nullable date as ISO-8601.
     */
    public static function date(?\DateTimeInterface $value): ?string
    {
        return $value?->format(\DateTimeInterface::ATOM);
    }
}
