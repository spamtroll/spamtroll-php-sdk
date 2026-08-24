<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Internal;

/**
 * Reads the three different error bodies the Spamtroll backend actually
 * returns, so callers never have to know which one they got.
 *
 * 1. Standard envelope — most handlers, the API key middleware, validation:
 *    `{"success": false, "error": {"code", "message", "request_id"}}`
 * 2. Quota envelope — HTTP 402, same shape plus `error.usage`.
 * 3. Legacy envelope — the HTTP rate limiter and the framework's own error
 *    handler: `{"error": true, "message": "..."}`.
 *
 * Shape 3 is why a naive `$decoded['error']` lookup produced the string
 * `"1"` for every rate-limited request: `error` is a boolean there, and the
 * human-readable text lives in `message`.
 *
 * Every string that leaves this class is truncated. The values come from
 * whatever host answered the request, and they end up in host logs and
 * moderation panels.
 *
 * @internal
 */
final class ErrorEnvelope
{
    /** Upper bound for any server-supplied string handed back to callers. */
    public const MAX_MESSAGE_LENGTH = 512;

    public const FALLBACK_MESSAGE = 'API error';

    /**
     * Human-readable error text, never empty.
     *
     * @param array<string, mixed> $decoded
     */
    public static function message(array $decoded): string
    {
        $error = $decoded['error'] ?? null;

        if (is_array($error)) {
            $message = self::scalarString($error['message'] ?? null);
            if ($message !== null) {
                return $message;
            }
            $code = self::scalarString($error['code'] ?? null);
            if ($code !== null) {
                return $code;
            }
        }

        // Legacy envelope: `error` is the boolean flag, `message` is the text.
        $message = self::scalarString($decoded['message'] ?? null);
        if ($message !== null) {
            return $message;
        }

        // Only now consider `error` as a plain string. Booleans are excluded
        // on purpose — `(string) true` is `"1"`, which is not an error message.
        if (!is_bool($error)) {
            $flat = self::scalarString($error);
            if ($flat !== null) {
                return $flat;
            }
        }

        return self::FALLBACK_MESSAGE;
    }

    /**
     * Machine-readable code (`VALIDATION_ERROR`, `QUOTA_EXCEEDED`, …) so
     * plugins can branch without matching on prose. Null when the backend
     * did not send one — the legacy envelope never does.
     *
     * @param array<string, mixed> $decoded
     */
    public static function code(array $decoded): ?string
    {
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            $code = self::scalarString($error['code'] ?? null);
            if ($code !== null) {
                return $code;
            }
        }

        return self::scalarString($decoded['code'] ?? null);
    }

    /**
     * Request identifier for support correlation. The backend puts it inside
     * the error envelope, not at the top level.
     *
     * @param array<string, mixed> $decoded
     */
    public static function requestId(array $decoded): ?string
    {
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            $requestId = self::scalarString($error['request_id'] ?? null);
            if ($requestId !== null) {
                return $requestId;
            }
        }

        return self::scalarString($decoded['request_id'] ?? null);
    }

    /**
     * Truncate any server-controlled string to a length that is safe to log.
     */
    public static function truncate(string $value, int $limit = self::MAX_MESSAGE_LENGTH): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit) . '…';
    }

    private static function scalarString(mixed $value): ?string
    {
        if ($value === null || is_bool($value) || !is_scalar($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : self::truncate($string);
    }
}
