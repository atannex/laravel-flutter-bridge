<?php

declare(strict_types=1);

namespace Atannex\LaravelFlutterBridge\Http\Middleware;

use Closure;
use Atannex\LaravelFlutterBridge\Services\FlutterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FormatFlutterResponse
{
    /**
     * Headers that must survive re-wrapping (e.g. Retry-After on 429).
     *
     * @var list<string>
     */
    protected const PRESERVED_HEADERS = [
        'retry-after',
        'x-ratelimit-limit',
        'x-ratelimit-remaining',
        'x-ratelimit-reset',
        'www-authenticate',
    ];

    /**
     * Ensure outgoing responses comply with the enterprise Flutter envelope format.
     *
     * Exceptions thrown by inner middleware and controllers are rendered by the
     * routing pipeline before reaching this middleware, so validation (422),
     * authentication (401), throttling (429) and similar failures are wrapped
     * here and receive a default meta.error_code.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);

        if (is_array($data) && array_key_exists('success', $data) && array_key_exists('meta', $data)) {
            return $response;
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return $this->preserveHeaders($response, FlutterResponse::success($data, 'Success', $statusCode));
        }

        $message = is_array($data) && isset($data['message']) && is_string($data['message']) && $data['message'] !== ''
            ? $data['message']
            : (Response::$statusTexts[$statusCode] ?? 'Request failed');

        $errors = is_array($data) && isset($data['errors']) ? $data['errors'] : null;

        $errorCode = is_array($data) && isset($data['error_code']) && is_string($data['error_code'])
            ? $data['error_code']
            : FlutterResponse::defaultErrorCode($statusCode);

        return $this->preserveHeaders(
            $response,
            FlutterResponse::error($message, $statusCode, $errors, [], $errorCode)
        );
    }

    /**
     * Copy relevant headers from the original response.
     */
    protected function preserveHeaders(Response $original, JsonResponse $wrapped): JsonResponse
    {
        foreach (self::PRESERVED_HEADERS as $name) {
            if ($original->headers->has($name)) {
                $wrapped->headers->set($name, $original->headers->get($name));
            }
        }

        if ($original instanceof JsonResponse && $original->exception !== null) {
            $wrapped->withException($original->exception);
        }

        return $wrapped;
    }
}
