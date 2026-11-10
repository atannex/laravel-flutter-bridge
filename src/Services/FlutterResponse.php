<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Services;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class FlutterResponse
{
    /**
     * Build an enterprise success response.
     *
     * @param mixed $data
     * @param array<string, mixed> $meta
     */
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = 200,
        array $meta = []
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
            'meta' => self::buildMetadata($meta),
        ], $statusCode);
    }

    /**
     * Build an enterprise error response.
     *
     * When provided, $errorCode is exposed as meta.error_code so clients can
     * branch on a stable machine-readable code instead of the message.
     *
     * @param mixed $errors
     * @param array<string, mixed> $meta
     */
    public static function error(
        string $message = 'An error occurred',
        int $statusCode = 400,
        mixed $errors = null,
        array $meta = [],
        ?string $errorCode = null
    ): JsonResponse {
        if ($errorCode !== null) {
            $meta['error_code'] = $errorCode;
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
            'meta' => self::buildMetadata($meta),
        ], $statusCode);
    }

    /**
     * Build a paginated response compliant with Flutter PaginatedResponse format.
     *
     * @param array<string, mixed> $meta
     */
    public static function paginated(
        LengthAwarePaginator|CursorPaginator|Paginator $paginator,
        string $message = 'Data retrieved successfully',
        array $meta = []
    ): JsonResponse {
        $paginationMeta = [
            'per_page' => $paginator->perPage(),
            'has_more' => $paginator->hasMorePages(),
            'next_page_url' => $paginator->nextPageUrl(),
            'prev_page_url' => $paginator->previousPageUrl(),
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            $paginationMeta['current_page'] = $paginator->currentPage();
            $paginationMeta['last_page'] = $paginator->lastPage();
            $paginationMeta['total'] = $paginator->total();
        }

        $mergedMeta = array_merge($paginationMeta, self::buildMetadata($meta));

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'errors' => null,
            'meta' => $mergedMeta,
        ], 200);
    }

    /**
     * Build a validation error response (HTTP 422).
     *
     * @param array<string, list<string>> $errors
     */
    public static function validation(
        array $errors,
        string $message = 'The given data was invalid.'
    ): JsonResponse {
        return self::error($message, 422, $errors, [], 'validation_failed');
    }

    /**
     * Standard unauthorized response (HTTP 401).
     */
    public static function unauthorized(string $message = 'Unauthenticated'): JsonResponse
    {
        return self::error($message, 401, null, [], 'unauthenticated');
    }

    /**
     * Standard forbidden response (HTTP 403).
     */
    public static function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return self::error($message, 403, null, [], 'forbidden');
    }

    /**
     * Standard not found response (HTTP 404).
     */
    public static function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return self::error($message, 404, null, [], 'not_found');
    }

    /**
     * Default meta.error_code for an HTTP error status.
     */
    public static function defaultErrorCode(int $statusCode): ?string
    {
        return match ($statusCode) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            419 => 'session_expired',
            422 => 'validation_failed',
            426 => 'upgrade_required',
            429 => 'too_many_requests',
            503 => 'maintenance',
            default => $statusCode >= 500 ? 'server_error' : null,
        };
    }

    /**
     * Build standardized meta tags.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected static function buildMetadata(array $extra = []): array
    {
        $base = [
            'timestamp' => now()->toIso8601String(),
            'api_version' => (string) config('flutter-bridge.envelope.api_version', 'v1'),
        ];

        if (config('flutter-bridge.envelope.include_trace_id', true)) {
            $base['trace_id'] = (string) Str::uuid();
        }

        return array_merge($base, $extra);
    }
}
