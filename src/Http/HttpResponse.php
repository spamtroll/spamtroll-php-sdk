<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Http;

final class HttpResponse
{
    /**
     * @param int $statusCode Real HTTP status code. Never 0 — signal "no response" with an exception.
     * @param string $body Response body, verbatim.
     * @param array<string, string> $headers Lowercased header name => value.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }
}
