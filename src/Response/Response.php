<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Response;

use Spamtroll\Sdk\Internal\ErrorEnvelope;

class Response
{
    /**
     * @param array<string, mixed> $data      Decoded JSON body, as received.
     * @param ?string              $error     Human-readable error text, non-null when success is false.
     * @param ?string              $errorCode Machine-readable backend code, e.g. QUOTA_EXCEEDED.
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $httpCode,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
    ) {
    }

    public function isConnectionValid(): bool
    {
        return $this->success && $this->httpCode >= 200 && $this->httpCode < 300;
    }

    /**
     * Machine-readable error code. Prefer this over matching on `$error`,
     * which is prose and may be localised or reworded by the backend.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode ?? ErrorEnvelope::code($this->data);
    }

    /**
     * Request identifier, for correlating a user report with a backend log.
     *
     * The backend returns it inside the error envelope (`error.request_id`),
     * not at the top level, so both locations are searched.
     */
    public function getRequestId(): ?string
    {
        return ErrorEnvelope::requestId($this->data);
    }

    /**
     * Human-readable status message when the backend sent one. Searches the
     * error envelope, the legacy top-level `message`, and the success
     * envelope's `data.message` (used by /scan/feedback).
     */
    public function getMessage(): ?string
    {
        $error = $this->data['error'] ?? null;
        if (is_array($error)) {
            $message = self::stringOrNull($error['message'] ?? null);
            if ($message !== null) {
                return $message;
            }
        }

        $message = self::stringOrNull($this->data['message'] ?? null);
        if ($message !== null) {
            return $message;
        }

        $payload = $this->data['data'] ?? null;

        return is_array($payload) ? self::stringOrNull($payload['message'] ?? null) : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_bool($value) || !is_scalar($value)) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : ErrorEnvelope::truncate($string);
    }
}
